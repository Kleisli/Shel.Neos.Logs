<?php
declare(strict_types=1);

namespace Shel\Neos\Logs\Controller;

use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class LogsController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @Flow\InjectConfiguration(path="logFilesUrl", package="Shel.Neos.Logs")
     * @var string
     */
    protected $logFilesUrl;

    /**
     * @Flow\InjectConfiguration(path="exceptionFilesUrl", package="Shel.Neos.Logs")
     * @var string
     */
    protected $exceptionFilesUrl;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    /**
     * Renders the app to interact with the nodetype graph
     */
    public function indexAction(): void
    {
        try {
            $logFiles = array_map(function (string $logFile) {
                $filename = basename($logFile);
                return [
                    'name' => basename($logFile),
                    'identifier' => $filename,
                ];
            }, Files::readDirectoryRecursively($this->logFilesUrl, '.log'));
        } catch (FilesException $e) {
            $logFiles = [];
        }

        try {
            $exceptionFiles = array_map(function (string $exceptionFile) {
                $filename = basename($exceptionFile);
                $date = \DateTime::createFromFormat('YmdHi', substr($filename, 0, 12));
                return [
                    'name' => $exceptionFile,
                    'identifier' => $filename,
                    'date' => $date,
                    'excerpt' => strip_tags(strtok(Files::getFileContents($exceptionFile), "\n")),
                ];
            }, Files::readDirectoryRecursively($this->exceptionFilesUrl, '.txt'));
        } catch (FilesException $e) {
            $exceptionFiles = [];
        }

        usort($exceptionFiles, function ($a, $b) {
            if ($a['date'] > $b['date']) return -1;
            if ($a['date'] < $b['date']) return 1;
            return 0;
        });

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'logFiles' => $logFiles,
            'exceptions' => $exceptionFiles,
            'flashMessages' => $flashMessages,
        ]);
    }

    /**
     */
    public function showLogfileAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->logFilesUrl . '/' . $filename);
        $entries = [];
        $levels = [];
        $level = $this->request->hasArgument('level') ? $this->request->getArgument('level') : '';

        if ($filename && strpos($filepath, realpath($this->logFilesUrl)) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);

            $lineCount = preg_match_all('/([\d:\-\s]+)\s([\d]+)(\s+[:.\d]+)?\s+(\w+)\s+(.+)/', $fileContent, $lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $lineLevel = $lines[4][$i];

                $levels[$lineLevel] = true;

                if ($level && $lineLevel !== $level) {
                    continue;
                }

                $entries[]= [
                    'date' => $lines[1][$i],
                    'ip' => $lines[3][$i],
                    'level' => $lines[4][$i],
                    'message' => htmlspecialchars($lines[5][$i]),
                ];
            }
        } else {
            $this->addFlashMessage('', 'Logfile could not be read', Message::SEVERITY_ERROR);
        }

        $csrfToken = $this->securityContext->getCsrfProtectionToken();
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'csrfToken' => $csrfToken,
            'filename' => $filename,
            'entries' => $entries,
            'flashMessages' => $flashMessages,
            'levels' => array_keys($levels),
            'level' => $level,
        ]);
    }

    /**
     * Shows the content of a single exception identified by its filename
     */
    public function showExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->exceptionFilesUrl)) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $fileContent = 'Error: Exception not found';
        }

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'filename' => $filename,
            'content' => htmlspecialchars($fileContent),
            'flashMessages' => $flashMessages,
        ]);
    }

    /**
     * Deletes a single exception identified by its filename and redirects to the index action
     */
    public function deleteExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->exceptionFilesUrl)) !== false && file_exists($filepath)) {
            if (Files::unlink($filepath)) {
                $this->addFlashMessage('', sprintf('Exception %s deleted', $filename), Message::SEVERITY_OK);
            } else {
                $this->addFlashMessage('', sprintf('Exception %s could not be deleted', $filename), Message::SEVERITY_ERROR);
            }
        } else {
            $this->addFlashMessage('', sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }
}
