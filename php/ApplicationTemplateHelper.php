<?php

namespace eFloat\OffersBundle\Services;

use Application\Sonata\UserBundle\Entity\User;
use eFloat\CmsBundle\Handler\DoctrineDBALHandler;
use eFloat\OffersBundle\Entity\Application;
use eFloat\OffersBundle\Entity\ApplicationTemplate;
use eFloat\OffersBundle\Entity\MemberApplication;
use FOS\UserBundle\Util\TokenGenerator;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Class ApplicationTemplateHelper
 * @package eFloat\OffersBundle\Services
 */
class ApplicationTemplateHelper
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var DoctrineDBALHandler
     */
    private $logger;

    /**
     * @var TokenStorage
     */
    private $tokenStorage;

    /**
     * ApplicationTemplateService constructor.
     * @param Container $container
     * @param TokenStorage $tokenStorage
     * @param DoctrineDBALHandler $doctrineDBALHandler
     */
    public function __construct(
        Container $container,
        TokenStorage $tokenStorage,
        DoctrineDBALHandler $doctrineDBALHandler
    ) {
        $this->container = $container;
        $this->tokenStorage = $tokenStorage;
        $this->logger = $doctrineDBALHandler;
    }

    /**
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }
    
    /**
     * @return TokenStorage
     */
    public function getTokenStorage()
    {
        return $this->tokenStorage;
    }

    /**
     * @return DoctrineDBALHandler
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return User|null
     */
    public function getUser()
    {
        if ($this->getTokenStorage()->getToken() === null) {
            return null;
        }

        return $this->getTokenStorage()->getToken()->getUser();
    }

    /**
     * @param MemberApplication $memberApplication
     * @return array
     */
    protected function prepare(MemberApplication $memberApplication)
    {
        if ($this->getUser() === null) {
            return [
                false,
                ["message" => "Unauthorized", "code" => Response::HTTP_UNAUTHORIZED],
                []
            ];
        }

        $member = $memberApplication->getMember();

        $user = $this->getUser();

        if ($member->getId() != $user->getId()) {
            return [
                false,
                ["message" => "This user does not have access to this section'", "code" => Response::HTTP_FORBIDDEN],
                []
            ];
        }

        $application = $memberApplication->getApplication();

        if (!$application) {
            return [
                false,
                [
                    "message" => sprintf(
                        'The Application request for id "%d" was not found. Please try again using the link',
                        $memberApplication->getId()
                    ),
                    "code" => Response::HTTP_BAD_REQUEST
                ],
                []
            ];
        }

        $offer = $memberApplication->getOffer();

        return [
            true,
            [],
            [
                "memberApplication" => $memberApplication,
                "application"       => $application,
                "offer"             => $offer,
                "member"            => $member,
                "company"           => $offer->getCompany()
            ]
        ];
    }

    /**
     * @param MemberApplication $memberApplication
     * @return array
     */
    public function renderPdfTemplateType(MemberApplication $memberApplication)
    {
        list($success, $message, $data) = $this->prepare($memberApplication);

        if ($success === false) {
            return [$success, $message["message"], $message["code"]];
        }

        /** @var Application $application */
        $application = $data["application"];
        /** @var ApplicationTemplate $template */
        $template = $application->getTemplate();

        $client = new Client([
            "verify" => false,
            "http_errors" => false
        ]);

        $url = sprintf(
            "%s/api/v1/pdf/generate/%s",
            $this->getContainer()->getParameter("pdf_form_builder_server"),
            $template->getCode()
        );

        $placeholders = (new PdfVarsCollector(new TokenGenerator()))
            ->searchPlaceholderPair($template->getCanvasData(), $data);

        $pages = json_decode($template->getCanvasData(), true);

        $result = $client->post($url, [
            "json" => [
                "data" => $placeholders,
                "pages" => $pages
            ],
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_AUTOREFERER => 1,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0
        ]);

        if ($result->getStatusCode() === Response::HTTP_OK) {
            $response = json_decode($result->getBody(), true);
            return [
                true,
                $response["pdf"],
                Response::HTTP_OK
            ];

        } else {
            return [
                false,
                sprintf("Cannot generate pdf for MA with id %d", $memberApplication->getId()),
                Response::HTTP_INTERNAL_SERVER_ERROR
            ];
        }
    }

    /**
     * @param MemberApplication $memberApplication
     * @return array
     */
    public function renderHtmlTemplateType(MemberApplication $memberApplication)
    {
        list($success, $message, $data) = $this->prepare($memberApplication);

        if ($success === false) {
            return [$success, $message["message"], $message["code"]];
        }

        /** @var Application $application */
        $application = $data["application"];

        $twig = $this->getContainer()->get("twig");
        $template = $twig->createTemplate($application->getTemplate()->getHtml());
        $html = $template->render($data);
        $dompdf = $this->getContainer()->get('slik_dompdf');
        $dompdf->getpdf($html);
        $output = $dompdf->output();

        $pdfName = sprintf(
            "%s_%s.pdf",
            str_replace(' ', '_', $memberApplication->getApplicant1Investor()) . uniqid(),
            (new \DateTime())->format("Y-m-d_H-i-s")
        );

        $outputDir = $this->getOutputDir() . DIRECTORY_SEPARATOR . $pdfName;
        $result = file_put_contents($outputDir, $output);
        
        if ($result === false) {
            $this->getLogger()->customWriteToLogFile(["message" => sprintf(
                "Cannot generate pdf for MA with id %d. %s, %s line %s",
                $memberApplication->getId(),
                __CLASS__,
                __FUNCTION__,
                __LINE__
            )], true);
            
            return [
                false,
                sprintf(
                    "Cannot generate pdf for MA with id %d",
                    $memberApplication->getId()
                ),
                Response::HTTP_INTERNAL_SERVER_ERROR
            ];
        }

        $url = $this->getContainer()->get("router")->generate(
            "application_template",
            ["filename" => $pdfName],
            true
        );
        
        return [
            true,
            $url,
            Response::HTTP_CREATED
        ];
    }

    /**
     * @return bool|string
     */
    private function getOutputDir()
    {
        $web = realpath($this->getContainer()->getParameter("kernel.root_dir") . '/../web');

        list($status, $folder) = $this->createDirsRecursively($web, $this->foldersSequence());

        return $status === false ? false : $folder;
    }

    /**
     * @param $convertToPathString
     * @return array|string
     */
    public function foldersSequence($convertToPathString = false)
    {
        $sequence = ["temp", "files", "application_template"];

        if ($convertToPathString) {
            return implode("/", $sequence);
        }

        return $sequence;
    }

    /**
     * @param $path
     * @param array $newFoldersName
     * @return array
     */
    private function createDirsRecursively($path, array $newFoldersName)
    {
        $web = realpath($path);

        if ($web === false) {
            $this->getLogger()->customWriteToLogFile(["message" => sprintf(
                "Cannot get web dir. %s, %s line %s",
                __CLASS__,
                __FUNCTION__,
                __LINE__
            )], true);
            
            return [false, ""];
        }

        $newFolder = "";

        foreach ($newFoldersName as $index => $newFolderName) {
            if ($newFolder !== "") {
                $newFolder .= DIRECTORY_SEPARATOR . $newFolderName;
            } else {
                $newFolder = $web . DIRECTORY_SEPARATOR . $newFolderName;
            }

            if (!is_dir($newFolder)) {
                $result = mkdir($newFolder, 0777, true);

                if ($result === false) {
                    $this->getLogger()->customWriteToLogFile(["message" => sprintf(
                        "Cannot create %s dir. %s, %s line %s",
                        $newFolder,
                        __CLASS__,
                        __FUNCTION__,
                        __LINE__
                    )], true);
                    
                    return [false, ""];
                }
            }
        }

        return [true, $newFolder];
    }
}
