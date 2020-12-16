#!/usr/bin/python

from __future__ import unicode_literals, absolute_import, division, print_function

import argparse
import cgi
import logging
import os
import prelude
import sys

from lxml import etree
from flup.server.fcgi import WSGIServer

VERSION = b'5.1.0'
FCGI_SOCK = b"/var/run/httpd/secef/secef.sock"
IDMEF_NS = ('urn:iana:xml:ns:idmef', 'http://iana.org/idmef')
IDMEF_DTD = '-//IETF//DTD RFC XXXX IDMEF v1.0//EN'

_CONTENT_TYPES = (b'text/xml', b'application/text')

client = None
logger = logging.getLogger()

class DTDResolver(etree.Resolver):
    def __init__(self, *args, **kwargs):
        etree.Resolver.__init__(self, *args, **kwargs)
        self.dtd = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'idmef-message.dtd')

    def resolve(self, url, id, context):
        if id == IDMEF_DTD:
            return self.resolve_filename(self.dtd, context)
        return None


class Gateway(object):
    _parser = None

    def __init__(self):
        if Gateway._parser is None:
            parser = etree.XMLParser(load_dtd=False)
            parser.resolvers.add(DTDResolver())
            Gateway._parser = parser

    def convert(self, body):
        roots = ('Alert', 'Heartbeat')
        ad_types = ('boolean', 'byte', 'character', 'date-time', 'integer',
                    'ntpstamp', 'portlist', 'real', 'string', 'byte-string',
                    'xml')
        special_content = {
            'Action': 'description',
            'AlertIdent': 'alertident',
            'alertident': 'alertident',
        }
        special_content.update(dict.fromkeys(ad_types, 'data'))
        ignored_attrs = ('ntpstamp', )

        xml = etree.fromstring(body, parser=Gateway._parser)
        stack = []
        indices = []
        idmef = None
        for action, elem in etree.iterwalk(xml, events=('start', 'end')):
            ns = elem.tag.rpartition('}')[0][1:]
            if ns not in IDMEF_NS:
                # Ignore elements from foreign namespaces.
                continue

            localName = elem.tag[(len(ns) or -2) + 2:]
            if localName == 'IDMEF-Message':
                continue

            if action == 'start':
                if localName in roots:
                    idmef = prelude.IDMEF()

                # Convert the XML element name into a Prelude path
                # without an index.
                part = self.idmef_to_prelude(localName)

                if part == 'analyzer' and stack[-1] == 'analyzer({0})':
                    indices[-1] += 1
                    continue

                if localName not in ad_types:
                    stack.append(part)
                path = '.'.join(stack)
                if localName in special_content:
                    path = '%s.%s' % (path, special_content[localName])

                # If the current path refers to a list, compute the index
                # and mark the path as such.
                if prelude.IDMEFPath(path.format(0)).isList():
                    indices.append(len(idmef.get(path.replace('{0}', '%d') % tuple(indices))))
                    stack[-1] += "({0})"

            else:
                if len(stack) > 0:
                    for attr, value in elem.attrib.items():
                        if attr in ignored_attrs:
                            continue

                        path = "%s.%s" % ('.'.join(stack), self.idmef_to_prelude(attr))
                        path = path.replace('{0}', '%d') % tuple(indices)

                        try:
                            idmef.set(path, value)
                        except RuntimeError:
                            logger.warn("Wrong path for attribute: %s" % path)
                            raise

                    if elem.text is not None:
                        path = ('.'.join(stack)).replace('{0}', '%d') % tuple(indices)
                        if localName in special_content:
                            path = '%s.%s' % (path, special_content[localName])

                        try:
                            idmef.set(path, elem.text)
                        except RuntimeError:
                            logger.warn("Wrong path for content: %s" % path)
                            raise

                if localName == 'analyzer' and indices[-1] > 0:
                    indices[-1] -= 1
                    continue

                if localName not in ad_types:
                    if stack[-1].endswith('({0})'):
                        indices.pop()
                    stack.pop()

                if localName in roots:
                    logger.debug(repr(idmef).decode('iso-8859-1', errors='replace').encode('unicode_escape'))
                    client and client.sendIDMEF(idmef)

    @staticmethod
    def idmef_to_prelude(field):
        res = []
        for c in field:
            if c == '-':
                res.append('_')
            elif c.islower():
                res.append(c)
            else:
                if len(res):
                    res.append("_")
                res.append(c.lower())
        return ''.join(res)


def app(environ, start_response):
    if environ.get('CONTENT_TYPE') not in _CONTENT_TYPES:
        start_response(b'415 Unsupported Media Type', [(b'Content-Type', b'text/plain')])
        return(b'Wrong media type\n')

    try:
        remote = cgi.FieldStorage(environ['wsgi.input'], environ=environ)
        Gateway().convert(remote.value)
        start_response(b'200 OK', [(b'Content-Type', b'text/plain')])
        return(b'OK\n')
    except Exception as e:
        logger.exception("Internal error")
        start_response(b'500 Internal error', [(b'Content-Type', b'text/plain')])
        return(b'Error: check the logs for more information')


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="IDMEF to Prelude web gateway")
    parser.add_argument('--profile', help="Prelude profile to use.", default="secef")
    parser.add_argument('--sock', '-s', help="Path to the gateway's UNIX socket.", default=FCGI_SOCK)
    parser.add_argument('--debug', help="Enable debugging logs.", default=False, action='store_true')
    parser.add_argument('--dry-run', '-n', help="Do not actually forward the messages.",
                        dest='dry_run', action='store_true')
    args = parser.parse_args()
    logging.basicConfig(stream=sys.stdout, level=logging.DEBUG if args.debug else logging.INFO)

    if not args.dry_run:
        client = prelude.ClientEasy(args.profile, prelude.ClientEasy.PERMISSION_IDMEF_WRITE,
                                    "Prelude Web Gateway", "Web Gateway", "CS-SI", VERSION)
        client.start()
    WSGIServer(app, bindAddress=args.sock).run()
