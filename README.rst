SECEF web gateway (push mode)
#############################

This repository contains a web application that processes security alerts encoded using
IDMEFv1 XML messages and forwards them to a Prelude SIEM manager.

For more information about the Intrusion Detection Message Exchange Format (IDMEF) version 1,
see https://tools.ietf.org/html/rfc4765.

For more information about Prelude SIEM, see https://www.prelude-siem.org/.

Installation
============

This module has been tested with CentOS 7.x.

* Disable SELinux:

  ..  sourcecode:: sh

      sudo setenforce 0

  *Note*: you may want to disable SELinux permanently by editing ``/etc/selinux/config``

* Install dependencies:

  ..  sourcecode:: sh

      sudo yum install -y epel-release
      sudo yum install -y httpd mod_ssl prelude-tools python-flup python-lxml python-prelude

* Register the ``secef`` profile with Prelude SIEM's manager:

  ..  sourcecode:: sh

      sudo prelude-admin register "secef" "idmef:w" <manager address> --uid apache --gid apache

  *Note*: this step is mandatory, unless you plan to run the gateway in dry-run mode only (see usage below).
  See also https://www.prelude-siem.org/projects/prelude/wiki/InstallingAgentRegistration for
  more information about the registration process.

* Give access to the gateway's profile to apache:

  ..  sourcecode:: sh

      sudo usermod -a -G prelude apache

  *Note*: this step is mandatory, unless you plan to run the gateway in dry-run mode only (see usage below).

* Create the ``/usr/local/secef/`` folder on your machine and copy all the files in this repository
  into that folder.

* Install the gateway:

  *Note*: all files should initially lie inside /usr/local/secef (see above).

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


Configuration
=============

Various options influence how the gateway behaves.
You can set these options by creating the file ``/etc/sysconfig/secef``
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
  to forward alerts to Prelude SIEM.

  The profile must be registered with Prelude SIEM's manager beforehand.
  See the installation instructions for more information.

  Defaults to ``secef``.

- ``--sock`` indicates the full path to the socket that will be created
  by the gateway to communicate with the HTTP server using the WSGI
  protocol.

  Defaults to ``/var/run/httpd/secef/secef.sock``.

- ``--valid-dtd`` turns on DTD validation on the IDMEF messages.

  Since the original IDMEF RFC never became a proposed standard,
  there is no official document type associated with IDMEF messages.

  This application assumes that:

  * ``-//IETF//DTD RFC XXXX IDMEF v1.0//EN`` is used as the document type
  * ``urn:iana:xml:ns:idmef`` is used as the XML namespace.

  DTD validation is disabled by default for compatibility reasons.

By default, the gateway will listen for IDMEF messages on port 3128.
You can customize the listening port by editing ``secef.httpd.conf``.
You must edit both the value inside the ``Listen`` directive and the
virtual host configuration for the change to take effect.
Do not forget to restart the HTTP server after the change.

In addition, the gateway fully supports TLS. However, it is disabled by default.
You can set the ``SSLEngine`` directive to ``on`` inside the virtual host
definition in ``secef.httpd.conf`` to enable TLS.
You may also need to tweak other TLS-related settings inside the file
to match your environment.
Do not forget to restart the HTTP server after the change.


Usage
=====

Start the gateway
-----------------

To start the gateway, execute the following command:

..  sourcecode:: sh

    systemctl start httpd secef

Send a test IDMEF message
-------------------------

The gateway accepts messages whose content type is either ``application/xml``
or ``text/xml``. Any other content type will be rejected.

To use the gateway, just send your (XML-formatted) IDMEF messages to the gateway's
listening port (3128 by default), eg.

..  sourcecode:: sh

    curl -XPOST -d @./test.xml -H 'Content-Type: text/xml' http://web-gw.example.com:3128/

You can then use Prelude SIEM to check that the message was properly forwarded.
You may also check the web gateway's logs with ``journalctl`` if debugging logs
have been enabled in the gateway's options.
