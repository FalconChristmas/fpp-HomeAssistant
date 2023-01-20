
<script>
const SENSOR_DEVICE_CLASSES = {
        "apparent_power":["VA"],
        "aqi":[],
        "battery":["%"],
        "carbon_dioxide":["CO2"],
        "carbon_monoxide":["CO"],
        "current":["mA","A"],
        "date":[],
        "duration":["μs","ms","s","min","h","d","w","m","y"],
        "energy":["Wh","kWh","MWh"],
        "frequency":["Hz","kHz","MHz","GHz"],
        "gas":["m³","ft³"],
        "humidity":["%"],
        "illuminance":["lx","lm"],
        "monetary":["€","$","¢"],
        "nitrogen_dioxide":["µg/m³"],
        "nitrogen_monoxide":["µg/m³"],
        "nitrous_oxide":["µg/m³"],
        "ozone":["µg/m³"],
        "pm1":["µg/m³"],
        "pm10":["µg/m³"],
        "pm25":["µg/m³"],
        "power_factor":["%"],
        "power":["W","kW","BTU/h"],
        "pressure":["Pa","kPa","hPa","bar","cbar","mbar","mmHg","inHg","psi"],
        "reactive_power":["var"],
        "signal_strength":["dB","dBm"],
        "sulphur_dioxide":["µg/m³"],
        "temperature":["°C","°F","K"],
        "timestamp":[],
        "volatile_organic_compounds":["µg/m³"],
        "voltage":["mV","V"]
    };

var overlayModels = {};   // FPP Overlay Models cache
var gpios = {};           // FPP GPIO Inputs cache
var gpioInputConfig = []; // FPP GPIO Input config file data

var config = {};          // Plugin configuration

var nameRegex = "^[-a-zA-Z0-9_][-a-zA-Z0-9_]*$";

// From https://www.home-assistant.io/integrations/binary_sensor/#device-class
function getBinarySensorDeviceClassSelect(currentValue, mode) {
    var deviceClasses = [ 'None', 'battery', 'battery_charging', 'carbon_monoxide', 'cold', 'connectivity', 'door', 'garage_door', 'gas', 'heat', 'light', 'lock', 'moisture', 'motion', 'moving', 'occupancy', 'opening', 'plug', 'power', 'presence', 'problem', 'running', 'safety', 'smoke', 'sound', 'tamper', 'update', 'vibration', 'window' ];
    var input = "<td><select class='deviceClass'";
    if (mode != 'binary_sensor')
        input += " style='display: none;'";
    input += ">";
    for (var i = 0; i < deviceClasses.length; i++) {
        input += "<option value='" + deviceClasses[i] + "'";
        if (deviceClasses[i] == currentValue)
            input += " selected";
        input += ">" + deviceClasses[i] + "</option>";
    }
    input += "</select></td>";
    return input;
}

// From https://www.home-assistant.io/integrations/sensor/#device-class
function GetSensorDeviceClassSelect(currentDevice, currentUnit) {
    var input = "<td><select class='deviceClass' onchange='UpdateSensorUnitSelect(this, \"" + currentUnit + "\")'>";
    var selected = null;
    for (const [device, units] of Object.entries(SENSOR_DEVICE_CLASSES)) {
        if (selected == null) {
            selected = device;
        }
        input += "<option value='" + device + "'";
        if (device == currentDevice) {
            selected = device;
            input += " selected";
        }
        input += ">" + device + "</option>";
    }
    input += "</select></td>";

    input += "<td><select class='unitOfMeasure'>";
    for (const units of SENSOR_DEVICE_CLASSES[selected]) {
        input += "<option value='" + units + "'";
        if (units == currentUnit) {
            input += " selected";
        }
        input += ">" + units + "</option>";
    }
    input += "</select></td>";

    return input;
}

function UpdateSensorUnitSelect(deviceSelect) {
    var device = $(deviceSelect).val();

    // Remove all existing options
    var unitOfMeasureSelect = $(deviceSelect).parent().parent().find('.unitOfMeasure');
    unitOfMeasureSelect.find('option').remove();
    for (const units of SENSOR_DEVICE_CLASSES[device]) {
        unitOfMeasureSelect.append($('<option>', {
            value: units,
            text: units
        }));
    }
}

function HideShowDeviceClass(item) {
    if ($(item).val() == 'binary_sensor')
        $(item).parent().parent().find('.deviceClass').show();
    else
        $(item).parent().parent().find('.deviceClass').hide();
}

function GetComponentSelect(currentValue) {
    var input = "<td><select class='component' onChange='HideShowDeviceClass(this);'>";

    input += "<option value='binary_sensor'";
    if (currentValue == 'binary_sensor')
        input += ' selected';
    input += ">Binary Sensor</option>";

    input += "<option value='switch'";
    if (currentValue == 'switch')
        input += ' selected';
    input += ">Switch</option>";

    input += "</select></td>";
    return input;
}

function GetGPIOInputPinConfig(pin, stateTopic) {
    var gc = {};
    gc.enabled = true;
    gc.pin = pin;
    gc.rising = { "command": "MQTT", "multisyncCommand": false, "multisyncHosts": "", "args": [ stateTopic, "ON" ] };
    gc.falling = { "command": "MQTT", "multisyncCommand": false, "multisyncHosts": "", "args": [ stateTopic, "OFF" ] };

    return gc;
}

function EffectCommandUpdated(row, data) {
    if (data.command == null)
        return;

    var json = JSON.stringify(data);
    $(row).find('.runCommandJSON').html(json);
}

function ShowEffectCommand(button, modelName) {
    var item = $(button).parent().find('.runCommandJSON');
    var cmd = {};
    var json = $(item).text();

    if (json != '') {
        cmd = JSON.parse(json);
        cmd.args[0] = modelName;
    }

    allowMultisyncCommands = true;

    var args = {};
    args.title = 'Edit Effect Command';
    args.saveButton = 'Accept Changes';
    args.cancelButton = 'Cancel';
    args.showPresetSelect = true;
    args.validCommands = ["Overlay Model Effect"];

    ShowCommandEditor(button, cmd, 'EffectCommandUpdated', '', args);
}

function DeleteEffect(item) {
    var row = $(item).parent().parent();

    if ($(item).parent().parent().parent().parent().parent().find('.effects > tr').length == 1)
        $(item).parent().parent().parent().parent().parent().find('.effectsHead').hide();

    $(row).remove();
}

function AddLightEffect(item, modelName) {
    var effects = $(item).parent().parent().parent().parent().parent().find('.effects');
    var defaultName = 'Effect-' + PadLeft('' + ($(effects).find('tr').length+1), '0', 2);
    var rowStr = "<tr><td class='center' valign='middle'><div class='rowGrip'><i class='rowGripIcon fpp-icon-grip'></i></div></td><td><input type='text' size='32' maxlength='32' class='effectName' value='" + defaultName + "'></input></td><td><button type='button' class='buttons wideButton' onClick='ShowEffectCommand(this, \"" + modelName + "\");'><span class='hidden runCommandJSON'></span><i class='fas fa-cog'></i></button></td><td><button class='buttons btn-outline-danger' onClick='DeleteEffect(this);'><i class='fas fa-trash-alt'></i></button></td></tr>";
    $(effects).append(rowStr);

    $(item).parent().parent().parent().parent().parent().find('.effectsHead').show();
}

function SaveHAConfig() {
    var models = {};
    var errors = false;
    $('#modelsBody > tr').each(function() {
        var model = {};
        model.Name = $(this).find('.modelName').html();
        model.LightName = $(this).find('.lightName').val().trim();

        if (model.LightName == '') {
            model.LightName = model.Name.replace(/[^-a-zA-Z0-9_]/g, "");
            $(this).find('.lightName').val(model.LightName);
        }

        if (!RegexCheckData(nameRegex, model.LightName, "Light Name must contain only Letters, Numbers, Underscrores, and Hyphens")) {
            errors = true;
            return;
        }

        if ($(this).find('.modelEnabled').is(':checked')) {
            model.Enabled = 1;
        } else {
            model.Enabled = 0;
        }

        var effects = [];
        $(this).find('.effects > tr').each(function() {
            var effect = {};
            effect.Name = $(this).find('.effectName').val();

            if (!RegexCheckData(nameRegex, effect.Name, "Effect Name must contain only Letters, Numbers, Underscrores, and Hyphens")) {
                errors = true;
                return;
            }

            effect.Command = JSON.parse($(this).find('.runCommandJSON').html());
            effect.Command.args[0] = model.Name;
            effects.push(effect);
        });

        if (effects.length)
            model.Effects = effects;

        models[model.Name] = model;
    });

    if (errors)
        return;

    config['models'] = models;

    var sensors = {};
    $('#sensorsBody > tr').each(function() {
        var sensor = {};
        sensor.Name = $(this).find('.fppSensorName').html();
        sensor.Label = $(this).find('.fppSensorLabel').html();
        sensor.SensorName = $(this).find('.sensorName').val().trim();
        sensor.DeviceClass = $(this).find('.deviceClass').val();
        sensor.UnitOfMeasure = $(this).find('.unitOfMeasure').val();
        var name = sensor.Label.replace(/[^a-zA-Z0-9_]/g, '');

        if (sensor.SensorName == '') {
            sensor.SensorName = sensor.Name.replace(/[^-a-zA-Z0-9_]/g, "");
            $(this).find('.sensorName').val(sensor.SensorName);
        }

        if (!RegexCheckData(nameRegex, sensor.SensorName, "Sensor Name must contain only Letters, Numbers, Underscrores, and Hyphens")) {
            errors = true;
            return;
        }

        if ($(this).find('.sensorEnabled').is(':checked')) {
            sensor.Enabled = 1;
        } else {
            sensor.Enabled = 0;
        }

        sensors[name] = sensor;
    });

    if (errors)
        return;

    config['sensors'] = sensors;
    config['sensorUpdateFrequency'] = parseInt($('#sensorUpdateFrequency').val());

    var gpioInputConfigModified = false;
    var pins = {};
    $('#gpiosBody > tr').each(function() {
        var pin = {};
        pin.Pin = $(this).find('.pinNumber').html();
        pin.Component = $(this).find('.component').val();
        pin.DeviceName = $(this).find('.deviceName').val().trim();

        if (pin.DeviceName == '') {
            pin.DeviceName = pin.Pin;
            $(this).find('.deviceName').val(pin.Pin);
        }

        if (!RegexCheckData(nameRegex, pin.DeviceName, "GPIO HA Device Name must contain only Letters, Numbers, Underscrores, and Hyphens")) {
            errors = true;
            return;
        }

        if (pin.Component == 'binary_sensor')
            pin.DeviceClass = $(this).find('.deviceClass').val();

        if ($(this).find('.pinEnabled').is(':checked')) {
            pin.Enabled = 1;
        } else {
            pin.Enabled = 0;
        }

        pins[pin.Pin] = pin;

        if ((pin.Enabled) && ($(this).find('.component').val() == 'binary_sensor')) {
            var stateTopic = 'falcon/player/';
            if (settings.hasOwnProperty('HostName'))
                stateTopic += settings['HostName'];
            stateTopic += '/ha/binary_sensor/' + pin.DeviceName + '/state';

            var found = 0;
            for (var i = 0; i < gpioInputConfig.length; i++) {
                if (gpioInputConfig[i].pin == pin.Pin) {
                    found = 1;

                    if ((!gpioInputConfig[i].enabled) ||
                        (gpioInputConfig[i].rising.command != 'MQTT') ||
                        (gpioInputConfig[i].rising.args[0] != stateTopic) ||
                        (gpioInputConfig[i].rising.args[1] != 'ON') ||
                        (gpioInputConfig[i].falling.command != 'MQTT') ||
                        (gpioInputConfig[i].falling.args[0] != stateTopic) ||
                        (gpioInputConfig[i].falling.args[1] != 'OFF')) {
                        // FIXME, prompt the user to overwrite existing GPIO Input config for this pin
                        var gc = GetGPIOInputPinConfig(pin.Pin, stateTopic);
                        gpioInputConfig[i] = gc;
                        gpioInputConfigModified = true;
                    }
                }
            }

            if (!found) {
                var gc = GetGPIOInputPinConfig(pin.Pin, stateTopic);
                gpioInputConfig.push(gc);
                gpioInputConfigModified = true;
            }
        }
    });

    if (errors)
        return;

    if (gpioInputConfigModified) {
        var configStr = JSON.stringify(gpioInputConfig, null, 2);
        $.post('/api/configfile/gpio.json', configStr).done(function(data) {
            $.jGrowl('FPP GPIO Config Updated');
            SetRestartFlag(2);
            CheckRestartRebootFlags();
        }).fail(function() {
            alert('Error, could not save gpio.json config file.');
        });
    }

    config['gpios'] = pins;

    var configStr = JSON.stringify(config, null, 2);
    $.post('/api/configfile/plugin.fpp-HomeAssistant.json', configStr).done(function(data) {
        $.jGrowl('Home Assistant Config Saved');
        SetRestartFlag(2);
        CheckRestartRebootFlags();
    }).fail(function() {
        alert('Error, could not save plugin.fpp-HomeAssistant.json config file.');
    });
}

function LoadConfig() {
    $.ajax({
        url: '/api/configfile/plugin.fpp-HomeAssistant.json',
        async: false,
        success: function(data) {
            config = data;
        }
    });

    // GPIO Input Config file
    $.ajax({
        url: '/api/configfile/gpio.json',
        async: false,
        success: function(data) {
            gpioInputConfig = data;
        }
    });


    // Overlay Models
    $.get('/api/models', function(fppModels) {
        overlayModels = fppModels;
        $('#modelsBody').empty();

        for (var i = 0; i < fppModels.length; i++) {
            var row = "<tr><th><input type='checkbox' class='modelEnabled'";

            if ((config.hasOwnProperty('models')) &&
                (config['models'].hasOwnProperty(fppModels[i].Name)) &&
                (config['models'][fppModels[i].Name].Enabled))
                row += ' checked';

            row += "></th>" +
                "<td class='modelName'>" + fppModels[i].Name + "</td>";

            row += "<td><input type='text' class='lightName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('models')) &&
                (config['models'].hasOwnProperty(fppModels[i].Name)))
                row += config['models'][fppModels[i].Name].LightName;
            else
                row += fppModels[i].Name.replace(/[^-a-zA-Z0-9_]/g, "");

            row += "' /></td>";

            row += "<td valign='top'>";

            row += "<table class='effectsTable'>"
                + "<thead class='effectsHead' style='display: none;'>"
                    + "<tr><td></td><td>HA Effect Name</td><td>Edit</td><td>Del</td></tr>"
                    + "</thead>"
                + "<tbody class='effects'>";

            var hasEffectsDefined = false;
            if ((config.hasOwnProperty('models')) &&
                (config['models'].hasOwnProperty(fppModels[i].Name)) &&
                (config['models'][fppModels[i].Name].hasOwnProperty('Effects'))) {
                var effects = config['models'][fppModels[i].Name].Effects;
                for (var j = 0; j < effects.length; j++) {
                    hasEffectsDefined = true;
                    row += "<tr><td class='center' valign='middle'><div class='rowGrip'><i class='rowGripIcon fpp-icon-grip'></i></div></td><td><input type='text' size='32' maxlength='32' class='effectName' value='" + effects[j].Name + "'></input></td><td><button type='button' class='buttons wideButton' onClick='ShowEffectCommand(this, \"" + fppModels[i].Name + "\");'><span class='hidden runCommandJSON'>" + JSON.stringify(effects[j].Command) + "</span><i class='fas fa-cog'></i></button></td><td><button class='buttons btn-outline-danger' onClick='DeleteEffect(this);'><i class='fas fa-trash-alt'></i></button></td></tr>";
                }
            }

            row += "</tbody>"
                + "<tfoot class='tfoot'>"
                    + "<tr><td></td><td><input type='button' class='buttons' value='Add' onClick='AddLightEffect(this, \"" + fppModels[i].Name + "\");'></td></tr>"
                    + "</tfoot>"
                + "</table></td></tr>";

            $('#modelsBody').append(row);

            if (hasEffectsDefined)
                $('#modelsBody tr:last').find('.effectsHead').show();
        }

        $('.effects').sortable();
    });

    // Sensors (Temp/Voltage/etc.)
    $.get('/fppjson.php?command=getFPPstatus', function(fppStatus) {
        fppSensors = fppStatus.sensors;

        updateWarnings(fppStatus);

        $('#sensorsBody').empty();

        if (config.hasOwnProperty("sensorUpdateFrequency")) {
            $('#sensorUpdateFrequency').val(config['sensorUpdateFrequency']);
        }

        for (var i = 0; i < fppSensors.length; i++) {
            var row = "<tr><th><input type='checkbox' class='sensorEnabled'";
            var name = fppSensors[i].label.replace(/[^a-zA-Z0-9_]/g, '');

            if ((config.hasOwnProperty('sensors')) &&
                (config['sensors'].hasOwnProperty(name)) &&
                (config['sensors'][name].Enabled))
                row += ' checked';

            var label = fppSensors[i].label.replace(/: $/g, '');
            fppSensors[i].name = name;

            row += "></th>" +
                "<td class='fppSensorName'>" + label + " (" + fppSensors[i].formatted + ")</td>";

            row += "<td><input type='text' class='sensorName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('sensors')) &&
                (config['sensors'].hasOwnProperty(name)))
                row += config['sensors'][name].SensorName;
            else
                row += name;

            row += "' /></td>";

            row += "<td style='display: none;' class='fppSensorLabel'>" + fppSensors[i].label + "</td>";

            var deviceClass = '';
            var unitOfMeasure = '';
            if (config.hasOwnProperty('sensors') && config['sensors'].hasOwnProperty(name)) {
                deviceClass = config['sensors'][name].DeviceClass || fppSensors[i].valueType.toLowerCase();
                unitOfMeasure = config['sensors'][name].UnitOfMeasure || '';
            }
            row += GetSensorDeviceClassSelect(deviceClass, unitOfMeasure);

            row += "</tr>";

            $('#sensorsBody').append(row);
        }
    });

    // GPIO Inputs
    $.get('/api/gpio', function(fppGPIOs) {
        gpios = fppGPIOs;
        $('#gpiosBody').empty();

        for (var i = 0; i < fppGPIOs.length; i++) {
            var row = "<tr><th><input type='checkbox' class='pinEnabled'";

            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)) &&
                (config['gpios'][fppGPIOs[i].pin].Enabled))
                row += ' checked';

            row += "></th>" +
                "<td class='pinNumber'>" + fppGPIOs[i].pin + "</td>";

            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)))
                row += GetComponentSelect(config['gpios'][fppGPIOs[i].pin].Component);
            else
                row += GetComponentSelect('');


            row += "<td><input type='text' class='deviceName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)))
                row += config['gpios'][fppGPIOs[i].pin].DeviceName;
            else
                row += fppGPIOs[i].pin;

            row += "' /></td>";

            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)))
                row += getBinarySensorDeviceClassSelect(config['gpios'][fppGPIOs[i].pin].DeviceClass, config['gpios'][fppGPIOs[i].pin].Component);
            else
                row += getBinarySensorDeviceClassSelect('', 'binary_sensor');

            row += "</tr>";

            $('#gpiosBody').append(row);
        }
    });
}

$(document).ready(function() {
    LoadConfig();   
    $(document).tooltip();
});

</script>


<div id="warningsRow" class="alert alert-danger"><div id="warningsTd"><div id="warningsDiv"></div></div></div>
<div id="global" class="settings">
    <fieldset>
        <div class="row tablePageHeader">
            <div class="col-md">
                <h2>Home Assistant MQTT Discovery</h2>
            </div>
            <div class="col-md-auto ml-lg-auto">
                <div class="form-actions">
                    <input type='button' class='buttons btn-success' value='Save HA Config' onClick='SaveHAConfig();'>
                </div>
            </div>
        </div>

        <b>Discover FPP Overlay Models as RGB Lights:</b><br>
        <div class='fppTableWrapper fppTableWrapperAsTable'>
            <div class='fppTableContents'>
                <table id='modelsTable' class='fppSelectableRowTable'>
                    <thead>
                        <tr class='tblheader'>
                            <th title='Enable the Overlay Model as a Light in HA'>Enable</th>
                            <th title='FPP Overlay Model Name'>Model Name</th>
                            <th title='Light name as it appears in HA'>HA Light Name</th>
                            <th title='FPP Commands for HA Light effects'>FPP Commands for HA Light effects</th>
                        </tr>
                    </thead>
                    <tbody id='modelsBody'>
                    </tbody>
                </table>
            </div>
        </div>
        <br>

        <b>Discover FPP Sensors:</b><br>
        <div class='fppTableWrapper fppTableWrapperAsTable'>
            <div class='fppTableContents'>
                <table id='sensorsTable' class='fppSelectableRowTable'>
                    <thead>
                        <tr class='tblheader'>
                            <th title='Enable the FPP Sensor as a Sensor in HA'>Enable</th>
                            <th title='FPP Sensor Name'>FPP Sensor</th>
                            <th title='Sensor name as it appears in HA'>HA Sensor Name</th>
                            <th title='Device Class'>HA Device Class</th>
                            <th title='Unit of Measurement'>HA Unit</th>
                        </tr>
                    </thead>
                    <tbody id='sensorsBody'>
                    </tbody>
                </table>
            </div>
        </div>
        Sensor update frequency: <input id='sensorUpdateFrequency' type='number' min='1' max='3600' value='60'> seconds<br>
        <br>

        <b>Discover FPP GPIOs as Binary Sensors (inputs) and Switches (outputs):</b><br>
        <div class='fppTableWrapper fppTableWrapperAsTable'>
            <div class='fppTableContents'>
                <table id='gpiosTable' class='fppSelectableRowTable'>
                    <thead>
                        <tr class='tblheader'>
                            <th rowspan=2 title='Enable the GPIO for HA integration'>Enable</th>
                            <th rowspan=2 title='FPP GPIO Pin'>GPIO<br>Pin</th>
                            <th colspan=3>Home Assistant Device</th>
                        </tr>
                        <tr class='tblheader'>
                            <th title='Select whether a GPIO appears as a sensor input in HA or the GPIO is used as a switch from within HA'>Type</th>
                            <th title='Device name as it appears in HA'>Name</th>
                            <th title="Sensor input class, determines which icons are displayed for the sensor's status">Class</th>
                        </tr>
                    </thead>
                    <tbody id='gpiosBody'>
                    </tbody>
                </table>
            </div>
        </div>

        NOTE: Changes to overlay models state and fill color within FPP will not be reflected within Home Assistant.  The RGB Light is currently one-way with HA controlling FPP, but not vice versa.  GPIO changes are also one-way with the direction depending on the HA Device Type selected, sensors go from FPP to HA, switches go from HA to FPP.  If a 'switch' GPIO is changed within FPP via another source, this change will not be reflected in HA.<br>
    </fieldset>
</div>

