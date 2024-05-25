<?php
/**
 * @filesource   gwdat.php
 * @created      03.07.2019
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2019 smiley
 * @license      MIT
 */
declare(strict_types=1);

use BuildWars\GWDat\GWDatReader;

require_once __DIR__.'/../vendor/autoload.php';

$reader = new GWDatReader('D:\\Games\\GUILD WARS\\gw.dat');

$reader->read();
