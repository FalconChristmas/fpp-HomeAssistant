#!/bin/sh

echo "Running fpp-HomeAssistant PreStart Script"

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make
