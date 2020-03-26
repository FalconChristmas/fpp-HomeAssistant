#!/bin/bash

# DEBUG=1
DEBUG_LOG=${DEBUG_LOG:-${LOGDIR}/fpp-HomeAssistant.log}

die()
{
	echo $1 >&2
	exit 1
}

usage()
{
	echo "TODO: Fill usage in!"
}

OPTS=$(getopt -n $0 --options lt:d: --longoptions list,type:,data: -- "$@")

if [ -n "$DEBUG" ]; then
	echo "Full args: $*" >> $DEBUG_LOG
fi

# Die if they fat finger arguments, this program will be run as root
[ $? = 0 ] || die "Error parsing arguments. Try $PROGRAM_NAME --help"

eval set -- "$OPTS"
while true; do
	case $1 in
		-l|--list)
			echo "c++";
            exit 0;
		;;
		-h|--help)
			usage
			exit 0
		;;
		-v|--version)
			printf "%s, version %s\n" "$PROGRAM_NAME" "$PROGRAM_VERSION"
			exit 0
		;;
		--)
			# no more arguments to parse
			break
		;;
		*)
			printf "Unknown option %s\n" "$1"
			exit 1
		;;
	esac
done

