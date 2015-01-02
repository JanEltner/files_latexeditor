<?php

// Check if we are a user and get userid
OCP\JSON::checkLoggedIn();
$userid = OCP\USER::getUser();

// Get translator
$l = OC_L10N::get('files_latexeditor');

set_time_limit(0); //scanning can take ages

// Check if compiler is given and valid, otherwise use the default compiler
if(!isset($_POST['compiler']) || !($_POST['compiler'] === 'xelatex' || $_POST['compiler'] === 'pdflatex' || $_POST['compiler'] === 'latex')){
	$compiler = 'latex';
}
else{
	$compiler = $_POST['compiler']
}

// Getting the file names, dirs etc.
$dir = isset($_POST['path']) ? $_POST['path'] : '';
$file = isset($_POST['filename']) ? $_POST['filename'] : '';
$workdir = dirname(\OC\Files\Filesystem::getLocalFile(stripslashes($dir). $file));
$info = pathinfo($file);
$fileext = '.' . $info['extension'];
$projectname = trim(basename($file, $fileext));
$pdffile = $projectname . '.pdf';
$dvifile = $projectname . '.dvi';
$psfile = $projectname . '.ps';
$tocfile = $projectname . '.toc';
$logfile = $projectname . '.log';
$outpath = "/tmp/latex_" . $userid . "_" . $projectname;

// As we will write pdf/ps file(s) in the $dir, we need to known if it's writable
if(!\OC\Files\Filesystem::isCreatable(stripslashes($dir))) {
    OCP\JSON::error(array('data' => array('message' => 'As you don\'t have write permission in the owner directory, it\'s not possible to create output latex files.', 'output' => ''))); 
    exit();
}

//Generating commands
$copy_directory_tree_command = "rsync -av -f\"+ */\" -f\"- *\" $workdir/ $outpath";
$cd_command = "cd " . str_replace(' ','\ ',trim($workdir)) ;
if($compiler == 'xelatex' || $compiler == 'pdflatex'){
    $latex_command .= $compiler . " -output-directory $outpath $file";
}
else{
    $latex_command = "latex -output-directory=$outpath  $file ; cd $outpath; dvips  $dvifile ; ps2pdf $psfile";
}
$cleanup_command = "rm -rf $outpath";


//beginning now with direct work for compilation
$output = "========BEGIN COMPILE $psfile ======== \n "; // % $latex_command\n";

$return = shell_exec($copy_directory_tree_command . " && " . $cd_command . " && " . $latex_command);
$log = file_get_contents($outpath . '/' . $logfile);

while (preg_match('/Rerun to get cross-references right/',$log) || preg_match('/No file '.$tocfile.'/',$log)){
	$return = shell_exec($cd_command . " && " . $latex_command);
	$log = file_get_contents($outpath . '/' . $logfile);	
}


// ! at begining of a line indicate an error!
$errors = preg_grep("/^!/",explode("\n",$log)) ;
if ( empty($errors) === false ) {
    $log_array = explode("\n",$log);
    $error = "\n";
    foreach ( $errors as $line => $msg ) {
	for ( $i = $line ; $i <= $line + 5 ; $i++)
		$error .=  $log_array[$i]."\n";
    }
    OCP\JSON::error(array('data' => array('message' => $l->t('Compile failed with errors').' - <br/>', 'output' => nl2br($output . " % " . $latex_command . "\n" . $error ))));
    shell_exec($cleanup_command);
    exit;
}

// No PDF File !?
if (!file_exists($outpath . '/' . $pdffile)) {
    OCP\JSON::error(array('data' => array('message' => $l->t('Compile failed with errors').':<br/>', 'output' => nl2br($output . " % ". $latex_command."\n" . file_get_contents($outpath . '/' . $logfile)) )));
    shell_exec($cleanup_command);
    exit;
};


$output .= $return;
$output .= "\n========END COMPILE==========\n";

if(file_exists($workdir . '/' . $pdffile)){
	\OC\Files\Filesystem::unlink($workdir . '/' . $pdffile);
}
if ($compiler === 'latex'  && file_exists($workdir . '/' . $psfile) ){
	\OC\Files\Filesystem::unlink($workdir . '/' . $psfile);
}

if (!@rename(trim($outpath . '/' . $pdffile), trim($workdir . '/'. $pdffile))) {
    $errors = error_get_last();
    $output.="\n>>>> " . $l->t("COPY ERROR: ") . $errors['type'];
    $output.="\n>>>> " . "<br />\n" . $errors['message'];
    $output.="<strong>" . trim($outpath . '/' . $pdffile) . " to " . trim($workdir . '/' . $pdffile) . "</strong>";
} else {
    $output.="<strong> Copy " . trim($outpath . '/' . $pdffile) . " to " . trim($workdir . '/' . $pdffile) . "</strong>";
    if ($compiler === 'latex') {
        if (!@rename(trim($outpath . '/' . $psfile), trim($workdir . '/' . $psfile))) {
            $errors = error_get_last();
            $output.="\n>>>> " . $l->t("COPY ERROR: ") . $errors['type'];
            $output.="\n>>>> " . "<br />\n" . $errors['message'];
        } 
		else{
			$output.=" <strong> Copy " . trim($outpath . '/' . $psfile) . "</strong>";
		}
    }
}

$output.="\n>>>> " . $l->t("COPY DONE: ") . "\n";

if ( $compiler === 'latex' ) {
	$target = OCP\Files::buildNotExistingFileName(stripslashes($workdir), $psfile);
} 
else{
	$target = OCP\Files::buildNotExistingFileName(stripslashes($workdir), $pdffile);
}
$target = \OC\Files\Filesystem::normalizePath($target);
$meta =  \OC\Files\Filesystem::getFileInfo($target);

OCP\JSON::success(array('data' => array('output' => nl2br($output), 'path' => $dir, 'pdffile' => $pdffile, 'psfile' => $psfile, 'logfile' => $logfile)));
shell_exec($cleanup_command);
