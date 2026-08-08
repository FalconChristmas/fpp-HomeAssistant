#!/bin/sh

# Nothing to do here in the normal case.  fppd loads libfpp-HomeAssistant.so once at its own
# startup, and scripts/fpp_install.sh - plus FPP's own core-upgrade pass, which
# rebuilds every plugin that has a Makefile - is what produces it.  This hook
# used to run an unconditional 'make', which delayed every single fppd start by
# a full dependency check for a build that was almost always already done.  Only
# rebuild when the library is actually missing, which is the one case fppd cannot
# recover from on its own (e.g. an SD image cloned from a machine of a different
# architecture).

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..

if [ ! -f libfpp-HomeAssistant.so ]; then
    echo "Running fpp-HomeAssistant PreStart Script - libfpp-HomeAssistant.so is missing, rebuilding"
    make
fi
