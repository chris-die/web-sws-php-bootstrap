<?php
namespace Serato\SwsApp\Slim\Controller;

use Serato\SwsApp\Slim\Controller\AbstractController;
use Slim\Exception\NotFoundException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouterInterface;
use Highlight\Highlighter;

class JsonSchemaViewerController extends AbstractController
{
    const SCHEMAS_LIST_NAMED_ROUTE = 'schemasList';

    const HTML_VIEW = 'html';
    const JSON_VIEW = 'json';
    const JSONLIST_VIEW = 'jsonlist';

    const STYLE_SHEET = 'github-gist.css';

    /* @var RouterInterface */
    private $router;

    /* @var string */
    private $appName;

    /* @var string */
    private $schemaDirectoryPath;

    /* @var string */
    private $stylesDirectoryPath;

    /**
     * Construct the controller
     *
     * @param LoggerInterface   $logger                 A PSR-3 logger interface
     * @param RouterInterface   $router                 Slim router instance
     * @param string            $appName                Application name
     * @param string            $schemaDirectoryPath    Path to directory containing JSON schema files
     */
    public function __construct(
        LoggerInterface $logger,
        RouterInterface $router,
        string $appName,
        string $schemaDirectoryPath,
        string $stylesDirectoryPath
    ) {
        parent::__construct($logger);
        $this->router = $router;
        $this->appName = $appName;
        $this->schemaDirectoryPath = rtrim($schemaDirectoryPath, '/');
        $this->stylesDirectoryPath = rtrim($stylesDirectoryPath, '/');
    }

    /**
     * Execute the endpoint action
     *
     * @todo Specify void return type in PHP 7.1
     *
     * @param  Request     $request            Request interface
     * @param  Response    $response           Response interface
     * @param  array       $args               Request URI args
     *
     * @return Response
     */
    public function execute(Request $request, Response $response, array $args): Response
    {
        $requestEtags = self::getIfNoneMatchEtags($request);

        $baseUri = $this->router->pathFor(self::SCHEMAS_LIST_NAMED_ROUTE);

        $contentType = 'text/html';

        if (isset($args['view'])) {
            if (!in_array($args['view'], [self::HTML_VIEW, self::JSON_VIEW, self::JSONLIST_VIEW])) {
                throw new NotFoundException($request, $response);
            }
            if ($args['view'] === self::JSONLIST_VIEW) {
                // Display file list as JSON
                if (isset($args['file_name'])) {
                    throw new NotFoundException($request, $response);
                }
                $contentType = 'application/json';
                $content = json_encode([
                    'description' => $this->appName . ' - REST API JSON Schemas',
                    'baseUri' => $request->getUri()->getScheme() . '://' .
                                    $request->getUri()->getHost() . $baseUri .'/' . self::JSON_VIEW,
                    'items' => $this->getJsonFileList()
                ]);
            } else {
                // View individual file
                if (file_exists($this->schemaDirectoryPath . '/' . $args['file_name'])) {
                    $json = file_get_contents($this->schemaDirectoryPath . '/' . $args['file_name']);
                    if ($args['view'] === self::HTML_VIEW) {
                        $content = $this->makeHtmlDoc(
                            $this->makeJsonFileHtml($baseUri, $args['file_name'], $json),
                            $this->readCssFile()
                        );
                    }
                    if ($args['view'] === self::JSON_VIEW) {
                        $contentType = 'application/json';
                        $content = $this->makeJsonRefPaths($request, $json);
                    }
                } else {
                    throw new NotFoundException($request, $response);
                }
            }
        } else {
            $content = $this->makeHtmlDoc($this->makeFileListHtml($baseUri));
        }
        $this->setEtag(md5($content));
        $response = $response->withHeader('Content-type', $contentType);
        if (in_array($this->getEtag(), $requestEtags)) {
            $response = $response->withStatus(304);
        } else {
            $response = $response->withStatus(200)->write($content);
        }
        return $response;
    }

    private function makeJsonRefPaths(Request $request, string $json): string
    {
        # Append base URI to all $ref paths
        # Disabled for now
        if (false) {
            $baseUri = $request->getUri()->getScheme() . '://' .
                        $request->getUri()->getHost() .
                        $this->router->pathFor(self::SCHEMAS_LIST_NAMED_ROUTE) . '/' .
                        self::JSON_VIEW;

            preg_match_all('/\"\$ref\".?:.?\"(.*)\.json/', $json, $matches);

            if (count($matches) > 0 && is_array($matches[0])) {
                $i = 0;
                foreach ($matches[0] as $wholeMatch) {
                    $findInWholeMatch = $matches[1][$i];
                    $replacementInWholeMatch = $baseUri . '/' . $findInWholeMatch;
                    $replacementinJson = str_replace($findInWholeMatch, $replacementInWholeMatch, $wholeMatch);
                    $json = str_replace($wholeMatch, $replacementinJson, $json);
                    $i++;
                }
            }
            return $json;
        } else {
            return $json;
        }
    }

    private function makeFileListHtml(string $baseUri): string
    {
        $str = "<h2>JSON schema files:</h2>\n";
        $str .= "<ul>\n";
        foreach ($this->getJsonFileList() as $fileName) {
            $str .= "<li style=\"line-height:1.3em; width:600px;\">" .
                    "<div style=\"float:left; width:250px;\">$fileName</div>" .
                    " <small>" .
                    "<a href=\"$baseUri/" . self::HTML_VIEW . "/$fileName\">HTML</a>" .
                    " &middot; " .
                    "<a href=\"$baseUri/" . self::JSON_VIEW . "/$fileName\" target=\"_blank\">JSON</a>" .
                    "</small></li>\n";
        }
        $str .= "</ul>\n";
        $str .= "<p><a href=\"$baseUri/" . self::JSONLIST_VIEW . "\" target=\"_blank\">View list as JSON</p>";

        return $str;
    }

    private function makeJsonFileHtml(string $baseUri, string $fileName, string $json): string
    {
        $highlighter = new Highlighter();
        $hl = $highlighter->highlight('json', $json);

        $str = "<h2>$fileName</h2>\n";
        $str .= "<p><a href=\"$baseUri\">Back</a></p>\n";
        $str .= "<pre class=\"hljs {$hl->language}\">" . $this->makeJsonDocLinks($baseUri, $hl->value) . "</pre>\n";
        $str .= "<p><a href=\"$baseUri\">Back</a></p>\n";
        return $str;
    }

    private function makeJsonDocLinks(string $baseUri, string $body): string
    {
        preg_match_all('/".*\.json\#/', $body, $matches);

        foreach ($matches[0] as $match) {
            $jsonFileName = substr(rtrim($match, '#'), strrpos($match, '"') + 1);
            $jsonLink = '<a href="' . $baseUri . '/' . self::HTML_VIEW . '/' . $jsonFileName . '">' .
                        $jsonFileName . '</a>';
            $body = str_replace($jsonFileName, $jsonLink, $body);
        }

        return $body;
    }

    private function makeHtmlDoc(string $body, string $css = ''): string
    {
        return sprintf(
            $this->getHtmlTemplate(),
            $this->appName,
            $css,
            $this->appName,
            $body
        );
    }

    private function readCssFile(): string
    {
        return file_get_contents($this->stylesDirectoryPath . '/' . self::STYLE_SHEET);
    }

    private function getHtmlTemplate(): string
    {
        return <<<EOT
    <html>
        <head>
            <meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
            <title>%s - REST API JSON Schemas</title>
            <style>
            %s
            </style>
        </head>
        <body>
            <h1>%s REST API JSON Schemas</h1>
            %s
        </body>
    </html>
EOT;
    }

    private function getJsonFileList(): array
    {
        $files = [];
        foreach (glob($this->schemaDirectoryPath . '/*.json') as $filePath) {
            $files[] = substr($filePath, strrpos($filePath, '/') + 1);
        }
        return $files;
    }
}
