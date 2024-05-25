<?php
/**
 * Class GWDatReader
 *
 *
 * @created      03.07.2019
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2019 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace BuildWars\GWDat;

use InvalidArgumentException;
use RuntimeException;
use function crc32;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function is_readable;
use function is_resource;
use function realpath;
use function rsort;
use function substr;
use function unpack;
use function usort;
use function var_dump;
use const SORT_NUMERIC;

/**
 * @link https://wiki.guildwars.com/wiki/Gw.dat_file_format
 * @link https://web.archive.org/web/20230818011058/https://wiki.xentax.com/index.php/Guild_Wars_DAT
 *
 */
final class GWDatReader{

	/**
	 * 4 bytes (string), file type "3AN" and version (always 0x1a)
	 * 4 bytes (uint32), header size (always 32)
	 * 4 bytes (uint32), block size (always 512)
	 * 4 bytes (uint32), CRC of the first bytes (always 0x70adcb4c)
	 */
	private const GWDAT_HEADER = "\x33\x41\x4e\x1a\x20\x00\x00\x00\x00\x02\x00\x00\x70\xad\xcb\x4c";

	/**
	 * MFT header block (24 bytes)
	 *
	 * 4 bytes (string), signature, always "Mft" and 0x1a
	 * 8 bytes, unknown
	 * 4 bytes (uint32), number of entries in the MFT
	 * 4 bytes, unknown
	 * 4 bytes (uint32), CRC
	 */
	private const MFT_HEADER_BLOCK = 'a4Signature/x8/LEntries/x4/LCRC';

	/**
	 * MFT block (24 bytes)
	 *
	 * 8 bytes (uint64), offset of the data block
	 * 4 bytes (uint32), size of the data
	 * 2 bytes (uint16), compression
	 *     - 0 uncomressed
	 *     - 8 compressed
	 * 1 byte (uint8), content indicator
	 *     - 1
	 *     - 3
	 * 1 byte (uint8), content type
	 *     - 0
	 *     - 1
	 *     - 2 FFNA
	 *     - 11
	 *     - 12 ?
	 *     - 255 ?
	 * 4 bytes (uint32), ID
	 * 4 bytes (uint32), CRC
	 */
	private const MFT_BLOCK      = 'QOffset/LDatasize/SCompression/CContent/CContentType/LID/LCRC';
	private const MFT_BLOCK_SIZE = 24;
	private const HASH_SIZE      = 8;

	/**
	 * @var resource
	 */
	private $fh;

	private string $gwdat;
	private array  $MFT = [];

	/**
	 * GWDatReader constructor.
	 *
	 * @param string $GWpath
	 */
	public function __construct(string $GWDat){
		$this->gwdat = realpath($GWDat);

		if($this->gwdat === false || !is_readable($this->gwdat)){
			throw new InvalidArgumentException('gw.dat not readable');
		}

		$this->fh = fopen($this->gwdat, 'rb');

		if(!$this->fh){
			throw new RuntimeException('fopen error');
		}

		if(fread($this->fh, 16) !== $this::GWDAT_HEADER){
			throw new RuntimeException('invalid gw.dat header');
		}

	}

	/**
	 * @return void
	 */
	public function __destruct(){
		if(is_resource($this->fh)){
			fclose($this->fh);
		}
	}

	/**
	 * @todo
	 *
	 */
	public function read(){
		$this->readMFT();

		foreach($this->MFT as $k => $v){

			if($k < 16){
				continue;
			}

			fseek($this->fh, $v['Offset']);
			$data = fread($this->fh, $v['Datasize']);

			if(crc32($data) !== $v['CRC']){
				throw new RuntimeException('invalid data block');
			}

			if($v['Compression'] === 0){
				var_dump($data);
			}
			elseif($v['Compression'] === 8){
				// can't be bothered for now
				// https://xp-dev.com/svn/enigmama-EnigmaGw/Servers/FileServer/xentax.cpp
			}
		}

	}

	private function readMFT():void{
		$this->MFT = [];

		/**
		 * 8 bytes, offset of the MFT
		 * 4 bytes, size of the MFT
		 * 4 bytes, unknown (always 0)
		 */
		$rootblock = unpack('QOffset/LSize/x4', fread($this->fh, 16));

		// read raw MFT data
		fseek($this->fh, $rootblock['Offset']);

		$rawMFT = fread($this->fh, $rootblock['Size']);

		// read reserved MFT entries
		for($i = 0; $i < 16; $i++){ //
			$str = substr($rawMFT, ($i * $this::MFT_BLOCK_SIZE), $this::MFT_BLOCK_SIZE);

			$this->MFT[$i] = $i === 0
				? unpack($this::MFT_HEADER_BLOCK, $str)
				: unpack($this::MFT_BLOCK, $str);
		}

		if($this->MFT[0]['Signature'] !== "\x4d\x66\x74\x1a"){
			throw new RuntimeException('invalid MFT header');
		}

		// read Hashlist
		fseek($this->fh, $this->MFT[2]['Offset']);
		$rawHashtable = fread($this->fh, $this->MFT[2]['Datasize']);

		if(crc32($rawHashtable) !== $this->MFT[2]['CRC']){
			throw new RuntimeException('hash table CRC does not match');
		}

		$hashtable = [];
		for($i = 0; $i < ($this->MFT[2]['Datasize'] / $this::HASH_SIZE); $i++){
			$hashtable[$i] = unpack('LFileNumber/LFileOffset', substr($rawHashtable, ($i * $this::HASH_SIZE), $this::HASH_SIZE));
		}

		usort($hashtable, fn(array $a, array $b):int => ($a['FileOffset'] <=> $b['FileOffset']));

		$hashcounter = 0;
		$totalhashes = count($hashtable);

		while($hashtable[$hashcounter]['FileOffset'] < 16){
			$hashcounter++;
		}

		// read MFT entries
		for($i = 16; $i < $this->MFT[0]['Entries']; $i++){
			$this->MFT[$i] = unpack($this::MFT_BLOCK, substr($rawMFT, ($i * $this::MFT_BLOCK_SIZE), $this::MFT_BLOCK_SIZE));

			if($hashcounter < $totalhashes && isset($hashtable[$hashcounter]) && $i === $hashtable[$hashcounter]['FileOffset']){

				$this->MFT[$i]['FileNumber'][] = $hashtable[$hashcounter]['FileNumber'];

				while(
					($hashcounter + 1 < $totalhashes)
					&& isset($hashtable[($hashcounter + 1)])
					&& $hashtable[$hashcounter]['FileOffset'] === $hashtable[($hashcounter + 1)]['FileOffset']
				){
					++$hashcounter;

					$this->MFT[$i]['FileNumber'][] = $hashtable[$hashcounter]['FileNumber'];
				}

				++$hashcounter;
			}
			else{
				$this->MFT[$i]['FileNumber'][] = 0;
			}

			rsort($this->MFT[$i]['FileNumber'], SORT_NUMERIC);
		}

	}

}
