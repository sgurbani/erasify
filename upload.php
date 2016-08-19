<?php

$basedir = dirname(__FILE__) . '/../uploads/';

//based off the example at php.net: http://php.net/manual/en/features.file-upload.php

//JSON return array
$ra = array();

try {
	/** If we're just uploading (no cropping yet) */
	if(isset($_GET['uploadonly']))
	{
		if(!isset($_FILES['img']))
			throw new RuntimeException('Image not uploaded to server.');

		$file = $_FILES['img'];

		//error parameter from upload
		switch ($file['error'])
		{
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				throw new RuntimeException('No file sent.');
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new RuntimeException('Exceeded filesize limit.');
			default:
				throw new RuntimeException('Unknown error occured.');
		}

		// make sure extension is .jpg/.jpeg/.png/.gif and the MIME type is appropriate
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		if (false === $ext = array_search(
			$finfo->file($file['tmp_name']),
			array(
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png' => 'image/png',
				'gif' => 'image/gif',
			),
			true
		)) {
			throw new RuntimeException('Invalid file type.');
		}
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if($ext != "png" && $ext != "jpg" && $ext != "jpeg" && $ext != "gif")
			throw new RuntimeException('Invalid file type');

		//use the SHA1 hash to store the image data
		$sha = sha1_file($file['tmp_name']);
		$fnm = $basedir . $sha . '.' . $ext;

		//copy the file over
		move_uploaded_file($file['tmp_name'], $fnm);

		//put the SHA1 hash and file name in the return variable
		$ra['fnm'] = $sha . '.' . $ext;

		//get the true image height and width
		switch($ext)
		{
			case 'gif':
				$img = imagecreatefromgif($fnm);
				break;
			case 'jpg':
			case 'jpeg':
				$img = imagecreatefromjpeg($fnm);
				break;
			case 'png':
				$img = imagecreatefrompng($fnm);
				break;
		}
		$h = imagesy($img);
		$w = imagesx($img);

		//enable max width or height of 600, while maintaining aspect ratio
		if($h >= $w && $h > 600)	{
			$sf = 600 / $h;
		} else if ($w > $h && $w > 600)	{
			$sf = 600 / $w;
		} else {
			$sf = 1.0;
		}

		$ra['sf'] = $sf;

		$nh = floor($h * $sf);
		$nw = floor($w * $sf);

		$img_new = imagecreatetruecolor($nw, $nh);
		imagecopyresampled($img_new, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

		//encode as base 64
		ob_start();
		switch($ext)
		{
			case 'gif':
				imagegif($img_new);
				imagegif($img_new, $fnm);
				break;
			case 'jpg':
			case 'jpeg':
				imagejpeg($img_new);
				imagejpeg($img_new, $fnm);
				break;
			case 'png':
				imagepng($img_new);
				imagepng($img_new, $fnm);
				break;
		}
		$imgdata = ob_get_contents();
		ob_end_clean();

		$ra['data'] = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($fnm));

		//flag success
		$ra['success'] = true;

		//output as JSON
		header('Content-Type: application/json');
		echo json_encode($ra);
	}
	else
	{
		//get the POST variables we need
		if(!isset($_POST['fnm']) || !isset($_POST['x2']) || !isset($_POST['x1']) || !isset($_POST['y2']) || !isset($_POST['y1']))
			throw new RuntimeException('Variables not set.');

		//get the filepath to the file
		$fnm = $basedir . $_POST['fnm'];

		//load the image
		$ext = strtolower(pathinfo($fnm, PATHINFO_EXTENSION));
		if($ext != "png" && $ext != "jpg" && $ext != "jpeg" && $ext != "gif")
			throw new RuntimeException('Invalid file type');

		switch($ext)
		{
			case 'gif':
				$img = imagecreatefromgif($fnm);
				break;
			case 'jpg':
			case 'jpeg':
				$img = imagecreatefromjpeg($fnm);
				break;
			case 'png':
				$img = imagecreatefrompng($fnm);
				break;
		}

		if(!$img)
			throw new RuntimeException('Server failed to load image');

		//crop it based on the POSTed variables
		$h = $_POST['y2'] - $_POST['y1'];
		$w = $_POST['x2'] - $_POST['x1'];

		//if the height > 525 or width > 375, shrink it down
		if($h > 525 )
		{
			$img_new = imagecreatetruecolor(375, 525);
			imagecopyresampled($img_new, $img, 0, 0, $_POST['x1'], $_POST['y1'], 375, 525, $w, $h);
		} else { //don't worry about rescaling
			$img_new = imagecreatetruecolor($w, $h);
			imagecopyresampled($img_new, $img, 0, 0, $_POST['x1'], $_POST['y1'], $w, $h, $w, $h);
		}

		//resample to 150 DPI
		ob_start();
		switch($ext)
		{
			case 'gif':
				imagegif($img_new);
				break;
			case 'jpg':
			case 'jpeg':
				imagejpeg($img_new);
				break;
			case 'png':
				imagepng($img_new);
				break;
		}
		$img = ob_get_contents();
		ob_end_clean();
		$img = substr_replace($img, pack("Cnn", 0x01, 150, 150), 13, 5);

		//give headers then download file
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename='.basename($fnm));
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . strlen($img));
		echo $img;

		//delete uploaded file
		unlink($fnm);
	}
} catch (RuntimeException $e) {
	//set success flag to false
	$ra['success'] = false;

	//set the error message
	$ra['err'] = $e->getMessage();

	header('Content-Type: application/json');
	echo json_encode($ra);
}

?>
