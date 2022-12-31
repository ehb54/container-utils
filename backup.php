<?php

{}; ## for proper emacs perl-mode formatting

## start user config

## for image tags 

$tagprefix       = "backup_";     ## backup container tags will receive this prefix
$compresswith    = "pigz -f";
$compressext     = "gz";
$config_file     = "config.json";

$debug = 1;

## end user config


$self                 = __FILE__;
$hdir                 = __DIR__;

date_default_timezone_set('UTC');

$notes = <<<__EOD
usage: $self {options} 

manages container backups

Options

--help                : print this information and exit

--dryrun              : lists what would be done
--run                 : runs the backups
--config file         : specify configuration file [default "$config_file"]
--nocleanup           : leave backup images
--list-backup-images  : lists current backup images (images with tag prefix $tagprefix)
--clean-backup-images : removes backup images (images with tag prefix $tagprefix)

__EOD;

require "utility.php";
$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

$dryrun               = false;
$run                  = false;
$nocleanup            = false;
$listbackupimages     = false;
$cleanbackupimages    = false;

while( count( $u_argv ) && substr( $u_argv[ 0 ], 0, 1 ) == "-" ) {
    switch( $u_argv[ 0 ] ) {
        case "--help": {
            echo $notes;
            exit;
        }
        case "--dryrun": {
            array_shift( $u_argv );
            $dryrun = true;
            break;
        }
        case "--nocleanup": {
            array_shift( $u_argv );
            $nocleanup = true;
            break;
        }
        case "--list-backup-images": {
            array_shift( $u_argv );
            $listbackupimages = true;
            break;
        }
        case "--clean-backup-images": {
            array_shift( $u_argv );
            $cleanbackupimages = true;
            break;
        }
        case "--run": {
            array_shift( $u_argv );
            $run = true;
            break;
        }
        case "--config": {
            array_shift( $u_argv );
            if ( !count( $u_argv ) ) {
               error_exit( "Optoin --config requires an argument" );
            }
            $config_file = array_shift( $u_argv );
            break;
        }
      default:
        error_exit( "\nUnknown option '$u_argv[0]'\n\n$notes" );
    }        
}

if ( !file_exists( $config_file ) ) {
    error_exit( "$config_file does not exist" );
}

if ( count( $u_argv ) ) {
    error_exit( "\nUnknown option '$u_argv[0]'\n\n$notes" );
}    

# checks

if ( $run && $dryrun ) {
    error_exit( "--run & --dryrun are mutually exclusive" );
}

$json = json_decode( implode( "\n", preg_grep( '/^\s*#/', explode( "\n", file_get_contents( $config_file ) ), PREG_GREP_INVERT ) ), false );

foreach ( [ "servers", "syncsto" ] as $v ) {
    if ( !isset( $json->{$v} ) ) {
        error_exit( "$config_file does not contain the key '$v'" );
    }
}

foreach ( $json->servers as $k => $v ) {
    foreach ( [ "name", "fqdn", "user", "type", "dir" ] as $v2 ) {
        if ( !isset( $v->{$v2} ) ) {
            error_exit( "$config_file servers entry $k is incomplete, does not contain the key '$v2'" );
        }
    }
    if ( isset( $v->containers ) && isset( $v->exclude ) ) {
        error_exit( "$config_file servers entry $k : 'containers' and 'exclude' are mutually exclusive" );
    }        
}

foreach ( $json->syncsto as $k => $v ) {
    foreach ( [ "name", "fqdn", "user", "dir" ] as $v2 ) {
        if ( !isset( $v->{$v2} ) ) {
            error_exit( "$config_file syncsto entry $k is incomplete, does not contain the key '$v2'" );
        }
    }
}

if ( !$run && !$dryrun && !$listbackupimages && !$cleanbackupimages) {
    echo "Nothing to do\n";
    exit;
}

$date = trim( run_cmd( 'date +"%y%m%d%H%M"' ) );
$tag  = "${tagprefix}${date}";

## listbackupimages

if ( $listbackupimages ) {
    echoline('=');
    echo "--list-backup-images\n";
    echoline('=');
    foreach ( $json->servers as $k => $v ) {
        echoline();
        echo "$v->name\n";
        echoline();
        $cmd = "ssh $v->user@$v->fqdn $v->type image ls | grep -P '\s+$tagprefix'";
        echo run_cmd( $cmd, false );
    }
}

## cleanbackups

if ( $cleanbackupimages ) {
    echoline('=');
    echo "--clean-backup-images\n";
    echoline('=');
    foreach ( $json->servers as $k => $v ) {
        echoline();
        echo "$v->name\n";
        echoline();
        $cmd = "ssh $v->user@$v->fqdn $v->type image ls | grep -P '\s+$tagprefix' | awk '{ print $1 \":\" $2 }'";
        $res = explode( "\n", trim( run_cmd( $cmd, false) ) );
        foreach ( $res as $v2 ) {
            if ( !empty( $v2 ) ) {
                if ( get_yn_answer( "remove backup image $v2 on server $v->name" ) ) {
                    $cmd = "ssh $v->user@$v->fqdn $v->type image rm $v2";
                    echo run_cmd( $cmd );
                }
            }
        }
    }
}

## dryrun

if ( $dryrun ) {
    foreach ( $json->servers as $k => $v ) {
        echoline('=');
        echo "--dryrun\n";
        echoline('=');
        echo "$v->name\n";
        echoline('=');
        
        # get list of containers if not specified    

        if ( !isset( $v->containers ) ) {
            $cmd = "ssh $v->user@$v->fqdn $v->type ps --format '{{.Names}}'";
            if ( isset( $v->exclude ) ) {
                $cmd .= " | grep -Pv '^(" . implode( "|", $v->exclude ) . ")\$'";
            }
            $v->containers = explode( "\n", trim( run_cmd( $cmd ) ) );
        }

        # commit the image
        foreach ( $v->containers as $v2 ) {
            echoline('-');
            echo "$v2\n";
            echoline('-');
            echo "dry-run: run will commit container $v2 as image $v2:$tag\n";
            echo "dry-run: run will save image $v2:$tag as $v->dir/${v2}_$tag.tar.$compressext\n";
            echo "dry-run: run will remove backup image $v2:$tag\n";
        }
    }
    exit;
}

## run
if ( $run ) {
    foreach ( $json->servers as $k => $v ) {
        echoline('=');
        echo "$v->name\n";
        echoline('=');
        
        # get list of containers if not specified    

        if ( !isset( $v->containers ) ) {
            $cmd = "ssh $v->user@$v->fqdn $v->type ps --format '{{.Names}}'";
            if ( isset( $v->exclude ) ) {
                $cmd .= " | grep -Pv '^(" . implode( "|", $v->exclude ) . ")\$'";
            }
            $v->containers = explode( "\n", trim( run_cmd( $cmd ) ) );
        }

        # commit the images
        foreach ( $v->containers as $v2 ) {
            echoline('-');
            echo "$v2\n";
            echoline('-');
            $cmd = "ssh $v->user@$v->fqdn '$v->type commit $v2 $v2:$tag'";
            echo "commiting container $v2 as image $v2:$tag\n";
            echo run_cmd( $cmd );
            $cmd = "ssh $v->user@$v->fqdn 'cd $v->dir && $v->type save $v2:$tag | $compresswith > ${v2}_$tag.tar.$compressext'";
            echo "saving image $v2:$tag as $v->dir/${v2}_$tag.tar.$compressext\n";
            echo run_cmd( $cmd );
            if ( $nocleanup ) {
                echo "WARNING: --nocleanup specified, so backup images will be left. Make sure you have sufficient disk space!\n";
            } else {
                $cmd = "ssh $v->user@$v->fqdn '$v->type image rm $v2:$tag'";
                echo "removing backup image $v2:$tag\n";
                echo run_cmd( $cmd );
            }
        }

        # rsync to each syncto server
        foreach ( $json->syncsto as $v2 ) {
            $cmd = "ssh $v->user@$v->fqdn 'rsync -av $v->dir/ $v2->user@$v2->fqdn:$v2->dir'";
            echo run_cmd( $cmd );            
        }
    }
}
