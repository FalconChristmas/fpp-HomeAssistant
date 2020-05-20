
<script>

var overlayModels = {};   // FPP Overlay Models cache
var gpios = {};           // FPP GPIO Inputs cache
var gpioInputConfig = {}; // FPP GPIO Input config file data

var config = {};          // Plugin configuration

function GetDeviceClassSelect(currentValue, mode) {
    var deviceClasses = [ 'None', 'battery', 'cold', 'connectivity', 'door', 'garage_door', 'gas', 'heat', 'light', 'lock', 'moisture', 'motion', 'moving', 'occupancy', 'opening', 'plug', 'power', 'presence', 'problem', 'safety', 'smoke', 'sound', 'vibration', 'window' ];
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

function SaveHAConfig() {
    var models = {};
    $('#modelsBody > tr').each(function() {
        var model = {};
        model.Name = $(this).find('.modelName').html();
        model.LightName = $(this).find('.lightName').val().trim();

        if (model.LightName == '') {
            model.LightName = model.Name;
            $(this).find('.lightName').val(model.Name);
        }

        if ($(this).find('.modelEnabled').is(':checked')) {
            model.Enabled = 1;
        } else {
            model.Enabled = 0;
        }

        models[model.Name] = model;
    });

    config['models'] = models;

    var sensors = {};
    $('#sensorsBody > tr').each(function() {
        var sensor = {};
        sensor.Name = $(this).find('.fppSensorName').html();
        sensor.Label = $(this).find('.fppSensorLabel').html();
        sensor.SensorName = $(this).find('.sensorName').val().trim();
        var name = sensor.Label.replace(/[^a-zA-Z0-9_]/g, '');

        if (sensor.SensorName == '') {
            sensor.SensorName = sensor.Name;
            $(this).find('.sensorName').val(sensor.Name);
        }

        if ($(this).find('.sensorEnabled').is(':checked')) {
            sensor.Enabled = 1;
        } else {
            sensor.Enabled = 0;
        }

        sensors[name] = sensor;
    });

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

            row += "<td><input class='lightName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('models')) &&
                (config['models'].hasOwnProperty(fppModels[i].Name)))
                row += config['models'][fppModels[i].Name].LightName;
            else
                row += fppModels[i].Name;

            row += "' /></td>";

            row += "</tr>";

            $('#modelsBody').append(row);
        }
    });

    // Sensors (Temp/Voltage/etc.)
    $.get('/fppjson.php?command=getFPPstatus', function(fppStatus) {
        fppSensors = fppStatus.sensors;

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
                "<td class='fppSensorName'>" + label + "</td>";

            row += "<td><input class='sensorName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('sensors')) &&
                (config['sensors'].hasOwnProperty(name)))
                row += config['sensors'][name].SensorName;
            else
                row += name;

            row += "' /></td>";

            row += "<td style='display: none;' class='fppSensorLabel'>" + fppSensors[i].label + "</td>";

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


            row += "<td><input class='deviceName' size='32' maxlength='32' value='";
            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)))
                row += config['gpios'][fppGPIOs[i].pin].DeviceName;
            else
                row += fppGPIOs[i].pin;

            row += "' /></td>";

            if ((config.hasOwnProperty('gpios')) &&
                (config['gpios'].hasOwnProperty(fppGPIOs[i].pin)))
                row += GetDeviceClassSelect(config['gpios'][fppGPIOs[i].pin].DeviceClass, config['gpios'][fppGPIOs[i].pin].Component);
            else
                row += GetDeviceClassSelect('', 'binary_sensor');

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

<div id="global" class="settings">
    <fieldset>
        <legend>Home Assistant MQTT Discovery</legend>
        <b>Discover FPP Overlay Models as RGB Lights:</b><br>
        <div class='fppTableWrapper fppTableWrapperAsTable'>
            <div class='fppTableContents'>
                <table id='modelsTable'>
                    <thead>
                        <th title='Enable the Overlay Model as a Light in HA'>Enable</th>
                        <th title='FPP Overlay Model Name'>Model Name</th>
                        <th title='Light name as it appears in HA'>HA Light Name</th>
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
                <table id='sensorsTable'>
                    <thead>
                        <th title='Enable the FPP Sensor as a Sensor in HA'>Enable</th>
                        <th title='FPP Sensor Name'>FPP Sensor</th>
                        <th title='Sensor name as it appears in HA'>HA Sensor Name</th>
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
                <table id='gpiosTable'>
                    <thead>
                        <tr>
                            <th rowspan=2 title='Enable the GPIO for HA integration'>Enable</th>
                            <th rowspan=2 title='FPP GPIO Pin'>GPIO<br>Pin</th>
                            <th colspan=3>Home Assistant Device</th>
                        </tr>
                        <tr>
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
        <br>

        <input type='button' class='buttons' value='Save HA Config' onClick='SaveHAConfig();'>
        <br>
        <br>
        NOTE: Changes to overlay models state and fill color within FPP will not be reflected within Home Assistant.  The RGB Light is currently one-way with HA controlling FPP, but not vice versa.  GPIO changes are also one-way with the direction depending on the HA Device Type selected, sensors go from FPP to HA, switches go from HA to FPP.  If a 'switch' GPIO is changed within FPP via another source, this change will not be reflected in HA.<br>
    </fieldset>
</div>
