<?php

namespace PantheonSalesEngineering\GoComposer;


use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use ZipArchive;

class Installer implements InstallerInterface
{
    /**
     * @var RemoteFilesystem
     */
    private $remoteFs;
    /**
     * @var GoContext
     */
    protected $context;
    /**
     * @var IOInterface
     */
    protected $io;
    /**
     * @var array
     */
    private $installedCommand;
    /**
     * @var string
     */
    private $downloadUriTemplate;
    /**
     * @var string
     */
    private $binDir;
    /**
     * @var string|null
     */
    private $osType;
    /**
     * @var array
     */
    private $executableList;

    /**
     * GoDownloader constructor.
     * @param IOInterface $io
     * @param RemoteFilesystem $remoteFs
     * @param GoContext $context
     * @param string $downloadUriTemplate
     * @param array $installedCommand
     * @param array $executableList
     */
    public function __construct(
        IOInterface      $io,
        RemoteFilesystem $remoteFs,
        GoContext        $context,
        string           $downloadUriTemplate = "",
        array            $installedCommand = [],
        array            $executableList = []
    )
    {
        // Setup
        $this->io = $io;
        $this->remoteFs = $remoteFs;
        $this->context = $context;

        // Unique values
        $this->installedCommand = (!empty($installedCommand)) ? $installedCommand : ["go", "version"];
        $this->executableList = (!empty($executableList)) ? $executableList : [];
        $this->downloadUriTemplate = !empty($downloadUriTemplate) ? $downloadUriTemplate :
            'https://go.dev/dl/go${version}.${osType}-${architecture}.${format}';
    }

    /**
     * @param string $version
     * @return bool
     * @throws InvalidArgumentException|Exception
     */
    public function install(string $version): bool
    {

        $this->downloadExecutable($version);

        return true;
    }

    /**
     * @return string|false
     */
    public function isInstalled()
    {
        $process = new Process($this->installedCommand, $this->context->getBinDir());
        try {
            $process->run();
            $output = explode("\n", $process->getIncrementalOutput());
            return $output[0];
        } catch (ProcessFailedException $exception) {
            echo $exception->getMessage();
            return false;
        }
    }

    /**
     * @param string $version
     * @throws Exception
     */
    private function downloadExecutable(string $version)
    {
        $downloadUri = $this->buildDownloadLink($version);

        $fileName = $this->context->getVendorDir() . DIRECTORY_SEPARATOR .
            pathinfo(parse_url($downloadUri, PHP_URL_PATH), PATHINFO_BASENAME);

        $this->remoteFs->copy(
            parse_url($downloadUri, PHP_URL_HOST),
            $downloadUri,
            $fileName,
            true
        );

        $targetPath1 = $this->context->getVendorDir() . DIRECTORY_SEPARATOR . pathinfo(parse_url($downloadUri, PHP_URL_PATH), PATHINFO_BASENAME);
        $targetPath = preg_replace('/\.(tar\.gz|zip)$/', '', $targetPath1);
        $this->unpackExecutable($fileName, $targetPath);
        $installPath = is_dir($targetPath . DIRECTORY_SEPARATOR . basename($targetPath)) ?
            $targetPath . DIRECTORY_SEPARATOR . basename($targetPath) :
            $targetPath;

        $this->linkExecutables($installPath, $this->context->getBinDir());
    }

    /**
     * @param string $version
     * @return string
     */
    private function buildDownloadLink(string $version): string
    {
        return preg_replace(
            [
                '/\$\{version\}/',
                '/\$\{osType\}/',
                '/\$\{architecture\}/',
                '/\$\{format\}/'
            ],
            [
                $version,
                strtolower($this->context->getOsType()),
                ArchitectureMap::getGoArchitecture($this->context->getSystemArchitecture()),
                $this->context->getOsType() === 'windows' ? 'zip' : 'tar.gz'
            ],
            $this->downloadUriTemplate
        );
    }

    /**
     * @param string $sourceDir
     * @param string $targetDir
     * @throws Exception
     */
    protected function linkExecutables(string $sourceDir, string $targetDir)
    {

        // Set up BinLinker.
        $fs = new BinLinker(
            $this->context->getBinDir(),
            $this->context->getOsType()
        );

        // Link files.
        foreach ($this->executableList as $name => $exec) {
            $link = $targetDir . DIRECTORY_SEPARATOR . $exec['link'];
            $fs->unlinkBin($link);
            // If Windows, replace with win executable.
            $execFile = ($this->context->getOsType() === 'windows') ? $exec['win'] : $exec['nix'];
            $execPath = $sourceDir . DIRECTORY_SEPARATOR . $execFile;
            $path = realpath($execPath);
            $fs->linkBin($path, $link);

        }
    }

    /**
     * @param $path
     * @return void
     */
    private function removeDirectory($path): void
    {
        // Check for files
        if (is_file($path)) {

            // If it is file then remove by
            // using unlink function
            unlink($path);
        }

        // If it is a directory.
        elseif (is_dir($path)) {

            // Get the list of the files in this
            // directory
            $scan = glob(rtrim($path, '/').'/*');

            // Loop through the list of files
            foreach($scan as $index=>$file_path) {

                // Call recursive function
                $this->removeDirectory($file_path);
            }

            // Remove the directory itself
            @rmdir($path);
        }
    }

    /**
     * @param string $source
     * @param string $targetDir
     */
    private function unpackExecutable(string $source, string $targetDir)
    {
        if (realpath($targetDir)) {
            $this->removeDirectory($targetDir);
        } else {
            mkdir($targetDir);
        }

        if (preg_match('/\.zip$/', $source) === 1) {
            $this->unzip($source, $targetDir);
        } else {
            $this->untar($source, $targetDir);
        }
    }

    /**
     * @param string $source
     * @param string $targetDir
     */
    private function unzip(string $source, string $targetDir)
    {
        $zip = new ZipArchive();
        $res = $zip->open($source);
        if ($res === true) {
            // extract it to the path we determined above
            $zip->extractTo($targetDir);
            $zip->close();
        } else {
            throw new RuntimeException(sprintf('Unable to extract file %s', $source));
        }

        unlink($source);
    }

    /**
     * @param string $source
     * @param string $targetDir
     */
    private function untar(string $source, string $targetDir)
    {
        $process = new Process(
            ["tar", "-xvf", $source, "--strip-components=1", "-C", $targetDir],
            $this->context->getVendorDir()
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'An error occurred while extracting (%s) to %s: %s',
                $source,
                $targetDir,
                $process->getErrorOutput()
            ));
        }

        unlink($source);
    }
}