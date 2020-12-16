SECEF web gateway (push mode)
#############################

Installation
============

This module has been tested with CentOS 7.x.

Disable SELinux:

..  sourcecode:: sh

    sudo setenforce 0

..  note::

    You may want to disable SELinux permanently by editing :file:`/etc/selinux/config`
    and rebooting.

Install dependencies:

..  sourcecode:: sh

    sudo yum install -y epel-release
    sudo yum install -y httpd mod_ssl prelude-tools python-flup python-lxml python-prelude

Register the "secef" profile with Prelude SIEM's manager:

..  note::

    This step is required, unless the gateway will only be run in dry-run mode (see usage below).
    See also https://www.prelude-siem.org/projects/prelude/wiki/InstallingAgentRegistration for
    more information about the registration process.

..  sourcecode:: sh

    sudo prelude-admin register "secef" "idmef:w" <manager address> --uid apache --gid apache

Give access to the gateway's profile to apache:

..  note::

    This step is required, unless the gateway will only be run in dry-run mode (see usage below).

..  sourcecode:: sh

    sudo usermod -a -G prelude apache

Create :file:`/usr/local/secef/` and copy all the files into that folder.

Install the gateway:

..  note::

    All files should initially lie inside /usr/local/secef

..  sourcecode:: sh

    sudo ln -s /usr/local/secef/secef.tmpfiles.conf /etc/tmpfiles.d/
    sudo ln -s /usr/local/secef/secef.httpd.conf    /etc/httpd/conf.d/
    sudo ln -s /usr/local/secef/secef.service       /etc/systemd/system/
    sudo ln -s /usr/local/secef/secef.xml           /etc/firewalld/services/
    systemd-tmpfiles --create /etc/tmpfiles.d/secef.tmpfiles.conf
    sudo systemctl daemon-reload
    sudo systemctl enable secef.service
    sudo systemctl restart httpd
    sudo systemctl reload firewalld
    sudo firewall-cmd --add-service=secef --permanent
    sudo firewall-cmd --add-service=secef

Usage
=====

The gateway accepts messages whose content type is either ``application/xml``
or ``text/xml``. Any other content type will be rejected.

To use the gateway, just send your (XML-formatted) IDMEF messages to the VA,
on TCP port 3128, eg.

..  sourcecode:: sh

    curl -XPOST -d @./test.xml -H 'Content-Type: text/xml' http://web-gw.example.com:3128/

..  warning::

    DTD validation is disabled by default.
    When enabled, it uses an old DTD where the XML namespace for IDMEF
    is defined as ``urn:iana:xml:ns:idmef``. You may want to change it
    if you plan to use this gateway in real life scenarios.

Various options influence how the gateway behaves.
You can set these options by creating the file :file:`/etc/sysconfig/secef`
with content similar to this one:

..  sourcecode:: sh

    OPTIONS="--dry-run --debug"

The following options are currently supported:

- ``--debug`` enables debugging logs, including the full contents of
  the IDMEF messages received by the gateway.

- ``--dry-run`` prevents the gateway from actually loading the Prelude SIEM
  profile and forwarding IDMEF messages to Prelude SIEM.
  This option can be used together with ``--debug`` to log the messages
  without actually forwarding them.

- ``--profile`` controls the name of the Prelude SIEM profile to use
  to forward alerts to Prelude SIEM. Defaults to ``secef``.
  The profile must be registered with Prelude SIEM's manager beforehand.
  See the installation instructions for more information.

- ``--sock`` indicates the full path to the socket that will be created
  by the gateway to communicate with the HTTP server using the WSGI
  protocol. Defaults to :file:`/var/run/httpd/secef/secef.sock`.
