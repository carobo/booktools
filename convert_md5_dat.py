import gzip
import csv
import io
import re
import codecs
from binascii import hexlify


def unescape(md5_escaped):
    md5_escaped = md5_escaped.replace(br'\0', b'\0')
    md5_escaped = md5_escaped.replace(br"\'", b"'")
    md5_escaped = md5_escaped.replace(br'\"', b'"')
    md5_escaped = md5_escaped.replace(br"\Z", b"\x1a")
    md5_escaped = md5_escaped.replace(br"\n", b"\n")
    md5_escaped = md5_escaped.replace(br"\r", b"\r")
    md5_escaped = md5_escaped.replace(br"\,", b",")
    md5_escaped = md5_escaped.replace(br"\\", b"\\")
    return md5_escaped


def extract_md5_from_dump(input_filename: str, output_filename: str):
    with gzip.open(input_filename, 'rb') as gz_file, open(output_filename, 'wb') as bin_file:
        _header = gz_file.readline()

        while True:
            data = gz_file.readline()
            if data is None:
                break

            assert(data.endswith(b'"\n'))
            separator = data.index(b'","')
            assert(separator >= 16)
            assert(separator <= 32)
            md5_escaped = data[1:separator]
            md5 = unescape(md5_escaped)
            assert(len(md5) == 16)
            bin_file.write(md5)
        

INPUT_FILE = 'aa_derived_mirror_metadata_20251106/mariadb/allthethings.aarecords_all_md5.00000.dat.gz'
OUTPUT_FILE = 'md5.bin'

if __name__ == "__main__":
    extract_md5_from_dump(INPUT_FILE, OUTPUT_FILE)
