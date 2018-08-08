<?php

require_once __DIR__ . '/vendor/autoload.php';

use Composer\Semver\Semver;
use Symfony\Component\Process\PhpProcess;

$splitter = new Splitter("D:\dev\code\dev-master", __DIR__ . '/repos');
$splitter->setLog(new \Monolog\Logger('log'));
$splitter->split(['mod_quiz']);


class Splitter
{
    protected $root;
    protected $outdir;

    /** @var \Psr\Log\LoggerInterface */
    protected $log;

    /**
     * Splitter constructor.
     * @param string $root
     * @param string $outdir
     */
    public function __construct($root, $outdir)
    {
        $this->root = $root;
        $this->outdir = $outdir;
        $this->log = new \Psr\Log\NullLogger();
    }

    /**
     * @param \Psr\Log\LoggerInterface $log
     */
    public function setLog(\Psr\Log\LoggerInterface $log): void
    {
        $this->log = $log;
    }

    public function git($command, $directory = null) {
        if (!is_null($directory)) {
            chdir($directory);
        }
        $this->log->debug("Git command: $command (" . getcwd() . ")");
        return `git $command`;
    }

    public function split(array $plugins = [])
    {
        if (!file_exists($this->outdir)) {
            mkdir($this->outdir);
        }

        if (!chdir($this->root)) {
            echo "Unable to switch to code directory\n";
            exit;
        }

        $fs = new \Symfony\Component\Filesystem\Filesystem();

        $tags = $this->git("tag");
        $tags = explode("\n", $tags);
        $tags = array_filter($tags);

        $tags = Semver::sort($tags);
        $tags = array_filter($tags, function ($tag) {
            return Semver::satisfies($tag, '>=3.4');
        });

        $tags = array_values($tags);

        foreach ($tags as $tag) {
            chdir($this->root);
            $this->log->info("Checking out $tag");
            $this->git("checkout $tag");
            foreach ($this->get_plugins($this->root) as $plugin => $plugindir) {
                if (!empty($plugins) && !in_array($plugin, $plugins)) {
                    continue;
                };

                $repodir = $this->outdir . '/moodle-' . $plugin;

                // Create repo if it doesn't exist.
                if (!file_exists($repodir)) {
                    $this->log->info("Creating repo for $plugin");
                    mkdir($repodir);
                    $this->git("init", $repodir);
                }

                // Check tag doesn't already exist for plugin.
                $plugintags = $this->git("tag", $repodir);
                $plugintags = explode("\n", $plugintags);
                $plugintags = array_filter($plugintags);

                if (in_array($tag, $plugintags)) {
                    $this->log->info("Tag $tag already exists for $plugin");
                    continue;
                }

                $repochildren = scandir($repodir);

                $ignorelist = ['.', '..', '.git'];

                // Delete everything but .git.
                foreach($repochildren as $child) {
                    if (in_array($child, $ignorelist)) {
                        continue;
                    }
                    $this->log->debug("Removing $child");
                    $fs->remove($repodir . DIRECTORY_SEPARATOR . $child);
                }

                // Copy everything from $plugindir to $repodir except .git.
                $plugindirchildren = scandir($plugindir);
                foreach($plugindirchildren as $child) {
                    if (in_array($child, $ignorelist)) {
                        continue;
                    }
                    $this->log->debug("Copying $child");
                    $childpath = $this->join_paths($plugindir, $child);
                    $targetpath = $this->join_paths($repodir, $child);
                    if (is_file($childpath)) {
                        $fs->copy($childpath, $targetpath);
                    } else if (is_dir($childpath)) {
                        $fs->mirror($childpath, $targetpath);
                    } else {
                        $this->log->warning("Unknown type $childpath");
                    }
                }

                // Add all, commit and tag.
                $this->log->info("Committing $plugin version $tag");
                $this->git("add .", $repodir);
                $this->git("commit -m \"Code for $tag\"", $repodir);
                $this->git("tag $tag", $repodir);

            }

        }

    }

// Get the plugins for a given version of Moodle. Runs in separate process so can be used for multiple
// checkouts in a single script run.
    function get_plugins($root)
    {
        $plugincode = file_get_contents(__DIR__ . '/get_plugins_process.php');
        $plugincode = str_replace('$moodleroot', '"' . $root . '"', $plugincode);
        $process = new PhpProcess($plugincode);
        $process->enableOutput();
        $process->run();
        return json_decode($process->getOutput());
    }

    /**
     * @param $plugindir
     * @param $child
     * @return string
     */
    public function join_paths($plugindir, $child): string
    {
        return $plugindir . DIRECTORY_SEPARATOR . $child;
    }

}

