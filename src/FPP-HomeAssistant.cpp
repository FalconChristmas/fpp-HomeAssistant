#include <fpp-pch.h>

#include <algorithm>
#include <atomic>
#include <unistd.h>
#include <errno.h>
#include <mutex>
#include <thread>

#include "commands/Commands.h"
#include "common.h"
#include "mqtt.h"
#include "settings.h"
#include "Plugin.h"
#include "log.h"
#include "overlays/PixelOverlay.h"
#include "overlays/PixelOverlayModel.h"
#include "sensors/Sensors.h"
#include "util/GPIOUtils.h"

class FPPHomeAssistantPlugin : public FPPPlugin, public httpserver::http_resource {
public:
    FPPHomeAssistantPlugin()
      : FPPPlugin("fpp-HomeAssistant"),
        sensorUpdateFrequency(60),
        sensorThread(nullptr),
        runSensorThread(false),
        lightThread(nullptr),
        runLightThread(false)
    {
        LogInfo(VB_PLUGIN, "Initializing Home Assistant Plugin\n");

        Json::Value root;
        if (LoadJsonFromFile(FPP_DIR_CONFIG("/model-overlays.json"), root)) {
            if (root.isMember("models")) {
                overlayModelConfig = root["models"];
            } else {
                Json::Value emptyArray(Json::arrayValue);
                overlayModelConfig = emptyArray;
            }
        } else {
            Json::Value emptyArray(Json::arrayValue);
            overlayModelConfig = emptyArray;
        }

        if (!LoadJsonFromFile(FPP_DIR_CONFIG("/gpio.json"), gpioConfig)) {
            Json::Value emptyArray(Json::arrayValue);
            gpioConfig = emptyArray;
        }

        if (LoadJsonFromFile(FPP_DIR_CONFIG("/plugin.fpp-HomeAssistant.json"), config)) {
            if (config.isMember("models")) {
                Json::Value::Members modelNames = config["models"].getMemberNames();
                for (unsigned int i = 0; i < modelNames.size(); i++) {
                    std::string lightName = config["models"][modelNames[i]]["LightName"].asString();
                    if (lightName == "") {
                        lightName = modelNames[i];
                        config["models"][modelNames[i]]["LightName"] = lightName;
                    }
                    lights[lightName] = config["models"][modelNames[i]];
                }

                std::function<void(const std::string &, const std::string &)> lf = [this](const std::string &topic, const std::string &payload) {
                    LightMessageHandler(topic, payload);
                };
                mqtt->AddCallback("/ha/light/#", lf);

                SendLightConfigs();

                runLightThread = true;
                lightThread = new std::thread([this]() {
                    PixelOverlayModel *model = nullptr;
                    Json::Value::Members modelNames = config["models"].getMemberNames();
                    std::map<std::string, std::string> states;
                    std::map<std::string, std::string>::iterator it;
                    Json::Value state;
                    Json::Value color;
                    std::string modelName;
                    std::string newStateStr;
                    std::string oldStateStr;
                    uint8_t *data = nullptr;
                    int brightness = 255;
                    std::unique_lock<std::mutex> lock(cacheLock);

                    lock.unlock();

                    state["brightness"] = brightness;
                    state["color_mode"] = "rgb";
                    state["color"] = color;

                    while (runLightThread) {
                        for (unsigned int i = 0; i < modelNames.size(); i++) {
                            modelName = modelNames[i];
                            if (!config["models"][modelName]["Enabled"].asInt())
                                continue;

                            model = PixelOverlayManager::INSTANCE.getModel(modelName);
                            if (model) {
                                state["state"] = (model->getState().getState() == PixelOverlayState::PixelState::Disabled) ? "OFF" : "ON";

                                lock.lock();

                                // Get brightness out of the cache so we can reverse it out of RGB values
                                if (cache[modelName].isMember("brightness")) {
                                    brightness = cache[modelName]["brightness"].asInt();
                                } else {
                                    brightness = 255;
                                }

                                // Get our current RGB values
                                data = model->getOverlayBuffer();
                                if (data) {
                                    state["color"]["r"] = std::min(255, (int)(1.0 * data[0] * 255 / brightness));
                                    state["color"]["g"] = std::min(255, (int)(1.0 * data[1] * 255 / brightness));
                                    state["color"]["b"] = std::min(255, (int)(1.0 * data[2] * 255 / brightness));
                                } else {
                                    state["color"]["r"] = 0;
                                    state["color"]["g"] = 0;
                                    state["color"]["b"] = 0;
                                }

                                // Save current RGB values back into the cache
                                cache[modelName]["color"] = state["color"];

                                lock.unlock();

                                state["brightness"] = brightness;

                                newStateStr = SaveJsonToString(state);

                                oldStateStr = "";
                                it = states.find(modelName);
                                if (it != states.end())
                                    oldStateStr = it->second;

                                if (oldStateStr != newStateStr) {
                                    states[modelName] = newStateStr;

                                    std::string stateTopic = "ha/light/";
                                    stateTopic += config["models"][modelName]["LightName"].asString();
                                    stateTopic += "/state";
                                    mqtt->Publish(stateTopic, newStateStr);
                                }
                            }
                        }

                        sleep(1);
                    }
                });
            }

            if (config.isMember("gpios")) {
                Json::Value::Members gpioNames = config["gpios"].getMemberNames();

                for (unsigned int i = 0; i < gpioNames.size(); i++) {
                    std::string deviceName = config["gpios"][gpioNames[i]]["DeviceName"].asString();
                    if (deviceName == "") {
                        deviceName = gpioNames[i];
                        config["gpios"][gpioNames[i]]["DeviceName"] = deviceName;
                    }
                    gpios[deviceName] = config["gpios"][gpioNames[i]];
                }

                std::function<void(const std::string &, const std::string &)> bsf = [this](const std::string &topic, const std::string &payload) {
                    BinarySensorMessageHandler(topic, payload);
                };
                mqtt->AddCallback("/ha/binary_sensor/#", bsf);

                std::function<void(const std::string &, const std::string &)> sf = [this](const std::string &topic, const std::string &payload) {
                    SwitchMessageHandler(topic, payload);
                };
                mqtt->AddCallback("/ha/switch/#", sf);

                SendGpioConfigs();
            }

            runSensorThread = false;
            if (config.isMember("sensors")) {
                Json::Value emptyArray(Json::arrayValue);
                Json::Value::Members sensorNames = config["sensors"].getMemberNames();
                sensorUpdateFrequency = config["sensorUpdateFrequency"].asInt();

                sensors = emptyArray;

                int  enabled = 0;
                for (unsigned int i = 0; i < sensorNames.size(); i++) {
                    if (config["sensors"][sensorNames[i]]["Enabled"].asInt()) {
                        sensors[enabled] = config["sensors"][sensorNames[i]];

                        std::string topic = "ha/sensor/";
                        topic += sensors[enabled]["SensorName"].asString() + "/state";

                        sensors[enabled]["Topic"] = topic;

                        haSensors[sensors[enabled]["SensorName"].asString()] = sensors[enabled];

                        enabled++;
                    }
                }

                std::function<void(const std::string &, const std::string &)> sf = [this](const std::string &topic, const std::string &payload) {
                    SensorMessageHandler(topic, payload);
                };
                mqtt->AddCallback("/ha/sensor/#", sf);

                if (enabled) {
                    SendSensorConfigs();

                    runSensorThread = true;
                    sensorThread = new std::thread([this]() {
                        std::string message;
                        unsigned int slept;

                        while (runSensorThread) {
                            Json::Value fppSensors;
                            Sensors::INSTANCE.reportSensors(fppSensors);

                            for (unsigned int i = 0; i < sensors.size(); i++) {
                                if (sensors[i]["Enabled"].asInt()) {
                                    for (unsigned int f = 0; f < fppSensors["sensors"].size(); f++) {
                                        if (fppSensors["sensors"][f]["label"].asString() == sensors[i]["Label"].asString()) {
                                            message = fppSensors["sensors"][f]["formatted"].asString();
                                            mqtt->Publish(sensors[i]["Topic"].asString(), message);
                                        }
                                    }
                                }
                            }

                            slept = 0;
                            while ((runSensorThread) && (slept++ < sensorUpdateFrequency)) {
                                sleep(1);
                            }
                        }
                    });

                }
            }
        }
    }

    virtual ~FPPHomeAssistantPlugin() {
        if (runSensorThread) {
            runSensorThread = false;
            sensorThread->join();
        }
        if (runLightThread) {
            runLightThread = false;
            lightThread->join();
        }
    }

private:
    Json::Value overlayModelConfig;
    Json::Value gpioConfig;

    Json::Value lights;
    Json::Value gpios;
    Json::Value haSensors;
    Json::Value config;
    Json::Value cache;
    std::mutex  cacheLock;

    int               sensorUpdateFrequency;
    Json::Value       sensors;
    std::thread      *sensorThread;
    std::atomic_bool  runSensorThread;
    std::thread      *lightThread;
    std::atomic_bool  runLightThread;

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting discovery
    void AddHomeAssistantDiscoveryConfig(const std::string &component, const std::string &id, Json::Value &config)
    {
        LogDebug(VB_PLUGIN, "Adding Home Assistant discovery config for %s/%s\n", component.c_str(), id.c_str());
        std::string cfgTopic = getSetting("MQTTHADiscoveryPrefix");

        bool hasCmd = true;
        bool hasState = true;

        if ((component == "binary_sensor") ||
            (component == "sensor")) {
            hasCmd = false;
        }

        if (cfgTopic.empty())
            cfgTopic = "homeassistant";

        cfgTopic += "/";
        cfgTopic += component;
        cfgTopic += "/";
        std::string hn = getSetting("HostName");
        if (hn == "") {
            hn = "FPP";
        }
        cfgTopic += hn;
        cfgTopic += "/";
        cfgTopic += id;
        cfgTopic += "/config";

        std::string cmdTopic = mqtt->GetBaseTopic();
        cmdTopic += "/ha/";
        cmdTopic += component;
        cmdTopic += "/";
        cmdTopic += id;

        std::string stateTopic = cmdTopic;
        stateTopic += "/state";
        cmdTopic += "/cmd";

        if (getSettingInt("MQTTHADiscoveryAddHost", 0)) {
            std::string name = getSetting("HostName");
            name += "_";
            name += id;
            config["name"] = name;
        } else {
            config["name"] = id;
        }

        if (hasState)
            config["state_topic"] = stateTopic;

        if (hasCmd)
            config["command_topic"] = cmdTopic;

        std::string uid = getSetting("HostName");
        uid += "_";
        uid += id;
        config["unique_id"] = uid;

        config["device"]["name"] = id;
        config["device"]["manufacturer"] = "Falcon Player";
        // TODO(edalquist) how do I get platform/version in code?
        // config["device"]["model"] = getSetting("Platform") + "(" + getSetting("Variant") + ")";
        config["device"]["configuration_url"] = "http://" + getSetting("HostName") + "/plugin.php?_menu=content&plugin=fpp-HomeAssistant&page=plugin_setup.php";
        config["device"]["sw_version"] = getFPPVersion();
        config["device"]["identifiers"] = Json::arrayValue;
        config["device"]["identifiers"].append(uid);
        config["device"]["via_device"] = getSetting("SystemUUID");

        std::string configStr = SaveJsonToString(config);
        mqtt->PublishRaw(cfgTopic, configStr);

        // Store a copy of this so we can detect when we remove models
        cfgTopic = "ha/";
        cfgTopic += component;
        cfgTopic += "/";
        cfgTopic += id;
        cfgTopic += "/config";
        mqtt->Publish(cfgTopic, configStr);
    }

    void RemoveHomeAssistantDiscoveryConfig(const std::string &component, const std::string &id)
    {
        LogDebug(VB_PLUGIN, "Removing Home Assistant discovery config for %s/%s\n", component.c_str(), id.c_str());
        std::string cfgTopic = getSetting("MQTTHADiscoveryPrefix");
        if (cfgTopic.empty())
            cfgTopic = "homeassistant";

        cfgTopic += "/";
        cfgTopic += component;
        cfgTopic += "/";
        cfgTopic += getSetting("HostName");
        cfgTopic += "/";
        cfgTopic += id;
        cfgTopic += "/config";

        std::string emptyStr;
        mqtt->PublishRaw(cfgTopic, emptyStr);

        // Clear our cache copy and any state message
        cfgTopic = "ha/";
        cfgTopic += component;
        cfgTopic += "/";
        cfgTopic += id;

        std::string stateTopic = cfgTopic;
        stateTopic += "/state";
        cfgTopic += "/config";

        mqtt->Publish(cfgTopic, emptyStr);
        mqtt->Publish(stateTopic, emptyStr);
    }

    void SendLightConfigs() {
        LogDebug(VB_PLUGIN, "Sending Overlay Model -> Light Configs\n");
        Json::Value::Members modelNames = config["models"].getMemberNames();
        std::unique_lock<std::mutex> lock(cacheLock);
        Json::Value info;

        for (unsigned int i = 0; i < modelNames.size(); i++) {
            if (!config["models"][modelNames[i]]["Enabled"].asInt())
                continue;

            std::string lightName = config["models"][modelNames[i]]["LightName"].asString();

            Json::Value s;

            s["schema"] = "json";
            s["qos"] = 0;
            s["brightness"] = true;
            s["rgb"] = true;
            s["effect"] = false;

            AddHomeAssistantDiscoveryConfig("light", lightName, s);

            // Put an empty entry in our cache to optimize code later
            cache[modelNames[i]] = info;
        }
    }

    void SendGpioConfigs() {
        LogDebug(VB_PLUGIN, "Sending GPIO -> Sensor/Switch Configs\n");
        Json::Value::Members gpioNames = config["gpios"].getMemberNames();
        for (unsigned int i = 0; i < gpioNames.size(); i++) {
            Json::Value gpio = config["gpios"][gpioNames[i]];

            if (!gpio["Enabled"].asInt())
                continue;

            Json::Value s;

            if (gpio["Component"].asString() == "binary_sensor") {
                s["device_class"] = gpio["DeviceClass"].asString();
            }

            AddHomeAssistantDiscoveryConfig(gpio["Component"].asString(), gpio["DeviceName"].asString(), s);
        }
    }

    void SendSensorConfigs() {
        LogDebug(VB_PLUGIN, "Sending Sensor Configs\n");

        Json::Value::Members sensorNames = config["sensors"].getMemberNames();
        for (unsigned int i = 0; i < sensorNames.size(); i++) {
            Json::Value sensor = config["sensors"][sensorNames[i]];

            if (!sensor["Enabled"].asInt())
                continue;

            Json::Value s;

            AddHomeAssistantDiscoveryConfig("sensor", sensor["SensorName"].asString(), s);
        }
    }

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting MQTT Lights
    bool ModelIsConfigured(const std::string &model) {
        if ((!config.isMember("models")) ||
            (!config["models"].isMember(model)))
            return false;

        return true;
    }

    virtual void LightMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/ha/light/LightName/cmd"

        std::string lightName = parts[3];
        if ((parts[4] == "config") &&
            ((!lights.isMember(lightName)) ||
             (lights[lightName]["Enabled"].asInt() == 0)) &&
            (!payload.empty())) {
            RemoveHomeAssistantDiscoveryConfig("light", lightName);
            return;
        }

        if (parts[4] != "cmd")
            return;

        LogDebug(VB_PLUGIN, "Received a light command for light: %s\n", lightName.c_str());
        LogExcess(VB_PLUGIN, "Command: %s\n", payload.c_str());

        if (!lights.isMember(lightName)) {
            LogErr(VB_PLUGIN, "No model found for light: %s\n", lightName.c_str());
            return;
        }

        std::string modelName = lights[lightName]["Name"].asString();

        Json::Value s = LoadJsonFromString(payload);

        std::string newState = toUpperCopy(s["state"].asString());

        std::unique_lock<std::mutex> lock(cacheLock);

        if (newState == "OFF") {
            Json::Value cmd;
            Json::Value args(Json::arrayValue);
            cmd["command"] = "Overlay Model Clear";
            args.append(modelName);
            cmd["args"] = args;
            CommandManager::INSTANCE.run(cmd);

            std::this_thread::sleep_for(std::chrono::milliseconds(250));

            cmd["command"] = "Overlay Model State";
            args.append("Disabled");
            cmd["args"] = args;
            CommandManager::INSTANCE.run(cmd);
        } else if (newState != "ON") {
            return;
        } else {
            LogExcess(VB_PLUGIN, "Light Config Received: %s\n", SaveJsonToString(s).c_str());

            if (!s.isMember("color")) {
                if (cache[modelName].isMember("color")) {
                    s["color"] = cache[modelName]["color"];
                    // Turning light on with no color and cached color is 0,0,0, so set full white
                    if ((s["color"]["r"].asInt() == 0) && (s["color"]["g"].asInt() == 0) && (s["color"]["b"].asInt() == 0)) {
                        s["color"]["r"] = 255;
                        s["color"]["g"] = 255;
                        s["color"]["b"] = 255;
                    }
                } else {
                    // Turning light on with no color and no cached color, so set full white
                    Json::Value c;
                    c["r"] = 255;
                    c["g"] = 255;
                    c["b"] = 255;
                    s["color"] = c;
                }
            }

            if (!s.isMember("brightness")) {
                if (cache[modelName].isMember("brightness")) {
                    s["brightness"] = cache[modelName]["brightness"].asInt();
                } else {
                    s["brightness"] = 255;
                }
            }

            LogExcess(VB_PLUGIN, "Merged Light Config: %s\n", SaveJsonToString(s).c_str());

            int brightness = s["brightness"].asInt();
            int r = (int)(1.0 * s["color"]["r"].asInt() * brightness / 255);
            int g = (int)(1.0 * s["color"]["g"].asInt() * brightness / 255);
            int b = (int)(1.0 * s["color"]["b"].asInt() * brightness / 255);

            char color[8];
            snprintf( color, 8, "#%02X%02X%02X", r, g, b);

            Json::Value cmd;
            Json::Value args(Json::arrayValue);
            cmd["command"] = "Overlay Model Fill";
            args.append(modelName);
            args.append("Enabled");
            args.append(color);
            cmd["args"] = args;
            LogExcess(VB_PLUGIN, "FPP Command: %s\n", SaveJsonToString(cmd).c_str());
            CommandManager::INSTANCE.run(cmd);
        }

        cache[modelName] = s;
    }

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting MQTT (Binary) Sensors
    bool BinarySensorIsConfigured(const std::string &model) {
        if ((!config.isMember("models")) ||
            (!config["models"].isMember(model)))
            return false;

        return true;
    }

    // Binary Sensors are 1-way, so we only need to handle removing them
    // here, not any actual commands from HA
    virtual void BinarySensorMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/ha/binary_sensor/SensorName/*"

        std::string sensorName = parts[3];
        if ((parts[4] == "config") &&
            ((!gpios.isMember(sensorName)) ||
             (gpios[sensorName]["Enabled"].asInt() == 0)) &&
            (!payload.empty())) {
            RemoveHomeAssistantDiscoveryConfig("binary_sensor", sensorName);
            return;
        }

        if (parts[4] != "cmd")
            return;

        LogDebug(VB_PLUGIN, "Somehow we received a binary_sensor command for sensor??? %s\n", sensorName.c_str());
        LogExcess(VB_PLUGIN, "Payload: %s\n", payload.c_str());
    }

    // Sensors are 1-way, so we only need to handle removing them
    // here, not any actual commands from HA
    virtual void SensorMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/ha/sensor/SensorName/*"
LogDebug(VB_PLUGIN, "Got a /sensor/ message: %s\n", topic.c_str());

        std::string sensorName = parts[3];
        if ((parts[4] == "config") &&
            ((!haSensors.isMember(sensorName)) ||
             (haSensors[sensorName]["Enabled"].asInt() == 0)) &&
            (!payload.empty())) {
            RemoveHomeAssistantDiscoveryConfig("sensor", sensorName);
            return;
        }

        if (parts[4] != "cmd")
            return;

        LogDebug(VB_PLUGIN, "Somehow we received a sensor command for sensor??? %s\n", sensorName.c_str());
        LogExcess(VB_PLUGIN, "Payload: %s\n", payload.c_str());
    }

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting MQTT Switches
    virtual void SwitchMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/switch/SwitchName/cmd"

        std::string switchName = parts[3];
        if ((parts[4] == "config") &&
            ((!gpios.isMember(switchName)) ||
             (gpios[switchName]["Enabled"].asInt() == 0)) &&
            (!payload.empty())) {
            RemoveHomeAssistantDiscoveryConfig("switch", switchName);
            return;
        }

        if (parts[4] != "cmd")
            return;

        LogDebug(VB_PLUGIN, "Received a switch command for: %s\n", switchName.c_str());
        LogExcess(VB_PLUGIN, "Payload: %s\n", payload.c_str());

        Json::Value cmd;
        Json::Value args(Json::arrayValue);
        cmd["command"] = "GPIO";
        args.append(gpios[switchName]["Pin"].asString());

        if (payload == "ON")
            args.append("true");
        else
            args.append("false");

        cmd["args"] = args;
        LogExcess(VB_PLUGIN, "FPP Command: %s\n", SaveJsonToString(cmd).c_str());
        CommandManager::INSTANCE.run(cmd);

        std::string stateTopic = "ha/switch/";
        stateTopic += switchName;
        stateTopic += "/state";
        mqtt->Publish(stateTopic, payload);
    }

};


extern "C" {
    FPPPlugin *createPlugin() {
        return new FPPHomeAssistantPlugin();
    }
}
