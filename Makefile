SRCDIR ?= /opt/fpp/src
include $(SRCDIR)/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libfpp-HomeAssistant.$(SHLIB_EXT)
debug: all

CFLAGS+=-I.
OBJECTS_fpp_HomeAssistant_so += src/FPP-HomeAssistant.o
LIBS_fpp_HomeAssistant_so += -L$(SRCDIR) -lfpp -ljsoncpp -lhttpserver
CXXFLAGS_src/FPP-HomeAssistant.o += -I$(SRCDIR)


%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-HomeAssistant.$(SHLIB_EXT): $(OBJECTS_fpp_HomeAssistant_so) $(SRCDIR)/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_HomeAssistant_so) $(LIBS_fpp_HomeAssistant_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-HomeAssistant.so $(OBJECTS_fpp_HomeAssistant_so)

