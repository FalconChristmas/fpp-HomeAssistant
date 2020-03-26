#!/bin/bash

# fpp-HomeAssistant uninstall script
echo "Running fpp-HomeAssistant uninstall Script"

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make clean

