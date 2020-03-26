#include <unistd.h>
//#include <ifaddrs.h>
#include <errno.h>
//#include <sys/types.h>
//#include <sys/socket.h>
//#include <arpa/inet.h>
//#include <cstring>
//#include <fstream>
//#include <list>
#include <mutex>
#include <thread>
//#include <vector>
//#include <sstream>
#include <jsoncpp/json/json.h>
//#include <cmath>

#include "FPP-HomeAssistant.h"

#include "commands/Commands.h"
#include "common.h"
#include "mqtt.h"
#include "settings.h"
#include "Plugin.h"
#include "log.h"
#include "util/GPIOUtils.h"

class FPPHomeAssistantPlugin : public FPPPlugin, public httpserver::http_resource {
public:
    FPPHomeAssistantPlugin() : FPPPlugin("fpp-HomeAssistant") {
        LogInfo(VB_PLUGIN, "Initializing Home Assistant Plugin\n");

        Json::Value root;
        if (LoadJsonFromFile("/home/fpp/media/config/model-overlays.json", root)) {
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

        if (!LoadJsonFromFile("/home/fpp/media/config/gpio.json", gpioConfig)) {
            Json::Value emptyArray(Json::arrayValue);
            gpioConfig = emptyArray;
        }

        if (LoadJsonFromFile("/home/fpp/media/config/plugin.fpp-HomeAssistant.json", config)) {
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
        }
    }

    virtual ~FPPHomeAssistantPlugin() {
    }

private:
    Json::Value overlayModelConfig;
    Json::Value gpioConfig;

    Json::Value lights;
    Json::Value gpios;
    Json::Value config;
    Json::Value cache;
    std::mutex  cacheLock;

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting discovery
    void AddHomeAssistantDiscoveryConfig(const std::string &component, const std::string &id, Json::Value &config)
    {
        LogDebug(VB_PLUGIN, "Adding Home Assistant discovery config for %s/%s\n", component.c_str(), id.c_str());
        std::string cfgTopic = getSetting("MQTTHADiscoveryPrefix");

        bool hasCmd = true;
        bool hasState = true;

        if (component == "binary_sensor") {
            hasCmd = false;
        }

        if (cfgTopic.empty())
            cfgTopic = "homeassistant";

        cfgTopic += "/";
        cfgTopic += component;
        cfgTopic += "/";
        cfgTopic += getSetting("HostName");
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

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting MQTT Lights
    bool ModelIsConfigured(const std::string &model) {
        if ((!config.isMember("models")) ||
            (!config["models"].isMember(model)))
            return false;

        return true;
    }

    virtual void LightMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/light/LightName/cmd"

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
            args.clear();
            args.append("Disabled");
            cmd["args"] = args;
            CommandManager::INSTANCE.run(cmd);

            if (cache.isMember(modelName)) {
                s = cache[modelName];
                s["state"] = "OFF";
            }
        } else if (newState != "ON") {
            return;
        } else {
            LogExcess(VB_PLUGIN, "Light Config Received: %s\n", SaveJsonToString(s).c_str());

            if (!s.isMember("color")) {
                if (cache.isMember(modelName) && cache[modelName].isMember("color")) {
                    s["color"] = cache[modelName]["color"];
                } else {
                    Json::Value c;
                    c["r"] = 255;
                    c["g"] = 255;
                    c["b"] = 255;
                    s["color"] = c;
                }
            }

            if (!s.isMember("brightness")) {
                if (cache.isMember(modelName) && cache[modelName].isMember("brightness")) {
                    s["brightness"] = cache[modelName]["brightness"];
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

        lock.unlock();

        std::string state = SaveJsonToString(s);

        std::string stateTopic = "ha/light/";
        stateTopic += lightName;
        stateTopic += "/state";
        mqtt->Publish(stateTopic, state);
    }

    /////////////////////////////////////////////////////////////////////////
    // Functions supporting MQTT Binary Sensors
    bool BinarySensorIsConfigured(const std::string &model) {
        if ((!config.isMember("models")) ||
            (!config["models"].isMember(model)))
            return false;

        return true;
    }

    // Binary Sensors are 1-way, so we only need to handle removing them
    // here, not any actual commands from HA
    virtual void BinarySensorMessageHandler(const std::string &topic, const std::string &payload) {
        std::vector<std::string> parts = split(topic, '/'); // "/binary_sensor/SensorName/cmd"

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
