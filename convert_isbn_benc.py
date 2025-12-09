import bencode2
import struct

path = 'aa_derived_mirror_metadata_20251106/codes_benc/aa_isbn13_codes_20251103T124740Z.benc'
with open(path, 'rb') as fp:
    data = bencode2.bdecode(fp.read())
    md5 = data[b'md5']

isbn = 0
offset = 0
count = len(md5) // 8
for _ in range(count):
    (streak, gap) = struct.unpack_from("<II", md5, offset)
    for i in range(streak):
        print(f"{isbn:010d}")
        isbn += 1
    isbn += gap + 1

(streak,) = struct.unpack_from("<I", md5, -4)
for i in range(streak):
    print(f"{isbn:010d}")
    isbn += 1