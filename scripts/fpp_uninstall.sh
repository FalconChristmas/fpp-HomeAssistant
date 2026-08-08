#!/bin/bash

# fpp-HomeAssistant uninstall script
echo "Running fpp-HomeAssistant uninstall Script"

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make clean


# No restartFlag: the Plugin Manager unloads the plugin through fppd before it
# removes these files, so the uninstall has already taken effect by the time
# this runs.
