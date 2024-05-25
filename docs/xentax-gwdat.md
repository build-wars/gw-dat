<!--
	originally from:
	https://wiki.xentax.com/index.php/Guild_Wars_DAT
	https://web.archive.org/web/20230812123748/http://wiki.xentax.com/index.php/Guild_Wars_DAT
	https://www.xen-tax.com/ (archive of XenTax.com)
-->

# Latest specs

## Header

32 bytes probably (see below)

    char{4}		'3AN' 0x1A (the '3' is the archive version no.)
    uint32{4}	Size of header (32)
    uint32{4}	Sector size (normally 0x200)
    uint32{4}	CRC of first 12 bytes
    uint64{8}	Offset of Main file table ('Mft') I think this is 64bit
    uint32{4}	Size of Main file table
    uint32(4)	Flags


## FFNA table

Unknown specs, it's a few tens of bytes in size, as written in the MFT table.
Usually written over out-dated MFT entries, as it fits in one block.
May have something to do with filenames.

New Info:
Actually most of the datafile consists of compressed ffna files, they most
probably contain various types of data (including 3d)
The ffna entries which are visible in the datafile are uncompressed files.
(does this actually stand for "ArenaNet File Format"?)


## FILEDATA

**Theory 1:**

First uint32 is similar between files, top 16 bits usually 01 02.
Then comes compressed data (?)
Then, for many files, 08 00 01 within a few bytes of the file's end.


**Theory 2:**

The first short of flags for the file (in MFT) shows compression/encryption of filedata.
Starts with int16 (0x0102) -- Dir Section?
If compressed the last quad of data is 0x08000180 then the uncompressed size (int32).


## ATEX - ATTX

Something like a DXT compressed texture.
One extraction of such completed by User:republicola

New Info:
Header structure:

    char{4} 'ATEX' - file format identifier
            'ATTX' - same file format as ATEX - these files store the terrain tile textures
    char{4} 'DXT?' - compression type, can be either DXT1 DXT2 DXT3 DXT4 DXT5 DXTA or DXTL
    uint16{2}      - x resolution
    uint16{2}      - y resolution
    uint32{4}      - data size (including this uint32)
    uint32{4}      - some kind of additional image compression type descriptor
                     possibly advances the file format to skip over bigger empty areas.
                     known values:
                     0x00 - no additional compression
                     0x08 - the most common one, followed by a bunch of unknown data and the
                            image data
                     0x09 - unknown - the file size is usually a lot smaller than the resolution
                            would suggest
                     0x0c - unknown

    Image compression types:
    DXT1 - The image is compressed with DXT1 compression, the color information and the 4x4 block
           information are stored in separate arrays in the file

    DXT2-5 - yet to be explored, probably modified versions of the original compression schemes


## MFT Master File Table structure

Every entry in the MFT is 0x18 bytes long, the first 16 entries are probably reserved for
internal usage/future expansions.

The very first entry is reserved for the header of the mft:

    char{4}		'Mft' 0x1A -> last byte was no version number, it's the same terminator as in the 3AN ID
    uint32{4}	Unknown (may also be a uint64{8} with the next var) (probably also some file counter)
    uint32{4}	Unknown
    uint32{4}	Number of entries in Mft table (including this one as entry 0)
    uint32{4}	Unknown
    uint32{4}	Unknown

The second entry is reserved for the header of the bigfile:

	uint64{8}	Offset of header structure (0)
	uint32{4}	Size of header structure (32)
	uint32{4}	Flag
	uint32{4}	Counter
	uint32{4}	CRC - not used

Third Entry in the mft is for the misteryous hash table.

	uint64{8}	Offset of (Filename) Hash info table?
	uint32{4}	Size of (Filename) Hash info table ?
	uint32{4}	Flag
	uint32{4}	Counter
	uint32{4}	CRC

Fourth entry in the mft is the self reference to the mft

	uint64{8}	Offset of Self (the Mft table itself)
	uint32{4}	Size of Self
	uint32{4}	Flag
	uint32{4}	Counter
	uint32{4}	CRC

The rest of the first 16 reserved entries are left blank (reserved)

After the 16 reserved entries comes the actual content information for the bigfile.

Theory 1:

	// For each file (entries of 0x18 again)
	uint64{8}	Offset of (compressed) file
	uint32{4}	Size of file
	uint32{4}	Flag
	uint32{4}	Counter (probably file ID)
	uint32{4}	CRC

CRC method unknown, but files with the same CRC32 had the same CRC in the MFT.

Theory 2:

	// For each file (entries of 0x18 again)
	uint64{8}	Offset of (compressed) file
	uint32{4}	Size of file
	uint32{2}	Compression Method (Same as ZIP. 0x00 = STORED, 0x08 = COMPRESSED)
	uint32{2}	Flag
	uint32{4}	Counter (probably file ID) / DOS Date/Time? (Basically, we're guessing here!)
	uint32{4}	CRC

    CRC method same as in zip. (Unconfirmed)

    <THEORY 2 ONLY:>
    >>

    Bit Flag

    char(1) = 0x01 / 0x02 / 0x03 (switch in EXE).
    char(1) = UNKNOWN... Known values (0x00 / 0x01 / 0x0b / 0x0c / 0xff)

    <<

## Flag bit meaning:

    Bit 3 - Compressed contents.

Is never set for FFNA or ATEX
Almost always set for compressed data.

    Bit 16 - Always set.

    Bit 17 - If set, and none of 24, 25 set, Counter is 0.

(the flag 3, 16, 17 is incredibly common)
Maybe incomplete data, as some files pointed by this have lots of FF bytes on their end.

    Bit 24 - Rarely set, if set, counter > 0.
    Bit 25 - Set more often than bit 24, counter > 0.

(the flag 3, 16, 17, 25 is also incredibly common)

        Bit 27 - Counter = 0, Most FFNAs have (16, 24, 25, 27)

The flags (16,17,24,25,26,27,28,29,30,31) and (3,16,17,24,25,26,27,28,29,30,31):
For both FFNAs and Compressed data, counter = 0
Contains multiple versions of the same file (Same uint64 at the beginning of the file, same len, different CRC32)

## Alternate flag meaning

The FLAG (uint32) field can be taken apart to three variables:

         uint16(2)         variable 'a'
         uint8(1)          variable 'b'
         uint8(1)          variable 'c'

Variable 'a' takes up only 2 values: 0x00 or 0x08
This is probably the compression flag

Variable 'b' takes up only 3 values: 0x00, 0x01 or 0x03
0x00 values are only taken by the 12 reserved Mft entries, so they should be ignored.
0x03 values probably indicate actual files in the archive (their number is exactly the number of
files downloaded with a missing datafile with the -image switch)

and finally
Variable 'c' takes up only 6 values: 0x00, 0x01, 0x02, 0x0b, 0x0c and 0xff
the 0x02 and 0x0b values are assigned to equal amounts of mft entries.
For each entry where 'c' takes up the value 0x02, the following entry
references an FFNA file (either compressed or not) in the archive, and takes
0x0b for the 'c' value.
The 0x01,0x0c and 0xff values are not present in the optimized file on the CD,
therefore they probably mark incomplete data.
0x01 is probably an incomplete file
0x0c is probably an incomplete directory entry
0xff is probably a file marked as deleted

This suggests that FFNA tables might actually not be game data after all,
but possible directory entries.

## (Filename) Hash Table??

    Entry:
    uint32 Unknown
    uint32 Counter

The counter is increasing, but not sequential. More about it in the next section.
Sometimes two entries have the same counter, usually when the top 16 bits of the Unknown are 02 00.

## The Counters

In both the MFT and the section described in the last section have counters.
If you combine the counters in both the MFT and that last section, they seem to be sequential.
Never does the same counter appear in both the MFT and the section described in the last section, and never does a counter repeat in an MFT.

