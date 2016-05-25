<?php

namespace Cheryl\File\Local;

use \Cheryl\Cheryl;

class Adapter {

	public function __construct() {
		$this->_root = Cheryl::me()->config['root'];

		$stat = intval(trim(shell_exec('stat -f %B '.escapeshellarg(__FILE__))));
		if ($stat && intval(filemtime(__FILE__)) != $stat) {
			Cheryl::me()->features->ctime = true;
		}

	}

	public function ls($dir, $filters = []) {

		if (is_file($dir)) {
			$file = new \SplFileObject($dir);
			$info = $this->getFileInfo($file, true);

			return [
				'type' => 'file',
				'file' => $info
			];

		} else {

			if (realpath($dir) == realpath($this->_root)) {
				$path = '';
				$name = '';
			} else {
				$dir = pathinfo($dir);
				$path = str_replace(realpath($this->_root),'',realpath($dir['dirname']));
				$name = basename($dir);
				$path = $path{0} == '/' ? $path : '/'.$path;
			}
			$info = [
				'path' => $path,
				'writeable' => is_writable($dir),
				'name' => $name
			];

			$files = $this->getFiles($dir, [
				'recursive' => $filters['recursive']
			]);

			return [
				'type' => 'dir',
				'list' => $files,
				'file' => $info
			];
		}
	}

	public function getFileInfo($file, $extended = false) {
		$path = str_replace(realpath($this->_root),'',realpath($file->getPath()));

		if ($file->isDir()) {
			$info = array(
				'path' => $path,
				'name' => $file->getFilename(),
				'writeable' => $file->isWritable(),
				'type' => $this->type($file, false)
			);

		} elseif (!$file->isDir()) {
			$info = array(
				'path' => $path,
				'name' => $file->getFilename(),
				'size' => $file->getSize(),
				'mtime' => $file->getMTime(),
				'ext' => $file->getExtension(),
				'writeable' => $file->isWritable()
			);

			if ($this->features->ctime) {
				$info['ctime'] = $this->cTime($file);
			}

			if ($extended) {
				$type = $this->type($file, true);

				$info['type'] = $type['type'];

				$info['meta'] = array(
					'mime' => $type['mime']
				);

				if ($type['type'] == 'text') {
					$info['contents'] = file_get_contents($file->getPathname());
				}

				if (strpos($mime, 'image') > -1) {
					if ($this->features->exif) {
						$exif = @exif_read_data($file->getPathname());

						if ($exif) {
							$keys = array('Make','Model','ExposureTime','FNumber','ISOSpeedRatings','FocalLength','Flash');
							foreach ($keys as $key) {
								if (!$exif[$key]) {
									continue;
								}
								$exifInfo[$key] = $exif[$key];
							}
							if ($exif['DateTime']) {
								$d = new \DateTime($exif['DateTime']);
								$exifInfo['Created'] = $d->getTimestamp();
							} elseif ($exif['FileDateTime']) {
								$exifInfo['Created'] = $exif['FileDateTime'];
							}
							if ($exif['COMPUTED']['CCDWidth']) {
								$exifInfo['CCDWidth'] = $exif['COMPUTED']['CCDWidth'];
							}
							if ($exif['COMPUTED']['ApertureFNumber']) {
								$exifInfo['ApertureFNumber'] = $exif['COMPUTED']['ApertureFNumber'];
							}
							if ($exif['COMPUTED']['Height']) {
								$height = $exif['COMPUTED']['Height'];
							}
							if ($exif['COMPUTED']['Width']) {
								$width = $exif['COMPUTED']['Width'];
							}
						}
					}
					$info['meta']['exif'] = $exifInfo;
				}
				if (!$height || !$width) {
					if ($this->features->gd) {
						$is = @getimagesize($file->getPathname());
						if ($is[0]) {
							$width = $is[0];
							$height = $is[1];
						}
					}
				}
				if ($height && $width) {
					$info['meta']['height'] = $height;
					$info['meta']['width'] = $width;
				}
			}

			$info['perms'] = $file->getPerms();

		}
		return $info;
	}

	public function getFiles($dir, $filters = []) {

		if ($filters['recursive']) {
			$iter = new \RecursiveDirectoryIterator($dir);
			$iterator = new \RecursiveIteratorIterator(
				$iter,
				\RecursiveIteratorIterator::SELF_FIRST,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			$filtered = new FilterIterator($iterator);

			$paths = array($dir);
			foreach ($filtered as $path => $file) {
				if ($file->getFilename() == '.' || $file->getFilename() == '..') {
					continue;
				}
				if ($file->isDir()) {
					$dirs[] = $this->getFileInfo($file);
				} elseif (!$file->isDir()) {
					$files[] = $this->getFileInfo($file);
				}
			}

		} else {
			$iter = new DirectoryIterator($dir);
			$filter = new FilterIterator($iter);
			$iterator = new \IteratorIterator($filter);

			$paths = array($dir);
			foreach ($iterator as $path => $file) {
				if ($file->isDot()) {
					continue;
				}
				if ($file->isDir()) {
					$dirs[] = $this->getFileInfo($file);
				} elseif (!$file->isDir()) {
					$files[] = $this->getFileInfo($file);
				}
			}
		}

		return [
			'dirs' => $dirs,
			'files' => $files
		];
	}


	// do our own type detection
	public function type($file, $extended = false) {

		$mime = mime_content_type($file->getPathname());

		$mimes = explode('/',$mime);
		$type = strtolower($mimes[0]);
		$ext = $file->getExtension();

		if ($ext == 'pdf') {
			$type = 'image';
		}

		$ret = array(
			'type' => $type,
			'mime' => $mime
		);

		if (!$extended) {
			return $ret['type'];
		} else {
			return $ret;
		}
	}

	public function getFile($file, $download = false) {
		if (!is_file($file)) {
			return false;
		}

		$file = new \SplFileObject($file);

		// not really sure if this range shit works. stole it from an old script i wrote
		if (isset($_SERVER['HTTP_RANGE'])) {
			list($size_unit, $range_orig) = explode('=', $_SERVER['HTTP_RANGE'], 2);
			if ($size_unit == 'bytes') {
				list($range, $extra_ranges) = explode(',', $range_orig, 2);
			} else {
				$range = '';
			}

			if ($range) {
				list ($seek_start, $seek_end) = explode('-', $range, 2);
			}

			$seek_end = (empty($seek_end)) ? ($size - 1) : min(abs(intval($seek_end)),($size - 1));
			$seek_start = (empty($seek_start) || $seek_end < abs(intval($seek_start))) ? 0 : max(abs(intval($seek_start)),0);

			if ($seek_start > 0 || $seek_end < ($size - 1)) {
				header('HTTP/1.1 206 Partial Content');
			} else {
				header('HTTP/1.1 200 OK');
			}
			header('Accept-Ranges: bytes');
			header('Content-Range: bytes '.$seek_start.'-'.$seek_end.'/'.$size);
			$contentLength = ($seek_end - $seek_start + 1);

		} else {
			header('HTTP/1.1 200 OK');
			header('Accept-Ranges: bytes');
			$contentLength = $file->getSize();
		}

		header('Pragma: public');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Date: '.date('r'));
		header('Last-Modified: '.date('r',$file->getMTime()));
		header('Content-Length: '.$contentLength);
		header('Content-Transfer-Encoding: binary');

		if ($download) {
			header('Content-Disposition: attachment; filename="'.$file->getFilename().'"');
			header('Content-Type: application/force-download');
		} else {
			header('Content-Type: '. mime_content_type($file->getPathname()));
		}

		// i wrote this freading a really long time ago but it seems to be more robust than SPL. someone correct me if im wrong
		$fp = fopen($file->getPathname(), 'rb');
		fseek($fp, $seek_start);
		while(!feof($fp)) {
			set_time_limit(0);
			print(fread($fp, 1024*8));
			flush();
			ob_flush();
		}
		fclose($fp);
		exit;
	}

	public function cTime($file) {
		return (int)trim(shell_exec('stat -f %B '.escapeshellarg($file->getPathname())));
	}

	public function deleteFile($file) {
		$status = false;

		if (is_dir($file)) {
			if ($this->config['recursiveDelete']) {
				foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($file), \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
					if ($path->getFilename() == '.' || $path->getFilename() == '..') {
						continue;
					}
					$path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
				}
			}

			if (rmdir($file)) {
				$status = true;
			}

		} else {
			if (is_writable($file) && unlink($file)) {
				$status = true;
			}
		}

		return $status;
	}

}
