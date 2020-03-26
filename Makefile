include /opt/fpp/src/makefiles/common/setup.mk
include /opt/fpp/src/makefiles/platform/*.mk

all: libfpp-HomeAssistant.so
debug: all

CFLAGS+=-I.
OBJECTS_fpp_HomeAssistant_so += src/FPP-HomeAssistant.o
LIBS_fpp_HomeAssistant_so += -L/opt/fpp/src -lfpp
CXXFLAGS_src/FPP-HomeAssistant.o += -I/opt/fpp/src


%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-HomeAssistant.so: $(OBJECTS_fpp_HomeAssistant_so) /opt/fpp/src/libfpp.so
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_HomeAssistant_so) $(LIBS_fpp_HomeAssistant_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-HomeAssistant.so $(OBJECTS_fpp_HomeAssistant_so)

