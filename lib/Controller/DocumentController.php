<?php
/**
 * ownCloud - Richdocuments App
 *
 * @author Victor Dubiniuk
 * @copyright 2014 Victor Dubiniuk victor.dubiniuk@gmail.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Richdocuments\Controller;

use OCA\Richdocuments\TokenManager;
use OCA\Richdocuments\WOPI\Parser;
use \OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use \OCP\IRequest;
use \OCP\IConfig;
use \OCP\IL10N;
use \OCP\AppFramework\Http\ContentSecurityPolicy;
use \OCP\AppFramework\Http\TemplateResponse;
use \OCA\Richdocuments\AppConfig;
use \OCA\Richdocuments\Helper;
use \OC\Files\View;
use OCP\ISession;
use OCP\Share\IManager;

class DocumentController extends Controller {
	/** @var string */
	private $uid;
	/** @var IL10N */
	private $l10n;
	/** @var IConfig */
	private $settings;
	/** @var AppConfig */
	private $appConfig;
	/** @var Parser */
	private $wopiParser;
	/** @var IManager */
	private $shareManager;
	/** @var TokenManager */
	private $tokenManager;
	/** @var ISession */
	private $session;
	/** @var IRootFolder */
	private $rootFolder;

	const ODT_TEMPLATE_PATH = '/assets/odttemplate.odt';

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $settings
	 * @param AppConfig $appConfig
	 * @param IL10N $l10n
	 * @param Parser $wopiParser
	 * @param IManager $shareManager
	 * @param TokenManager $tokenManager
	 * @param IRootFolder $rootFolder
	 * @param ISession $session
	 * @param string $UserId
	 */
	public function __construct($appName,
								IRequest $request,
								IConfig $settings,
								AppConfig $appConfig,
								IL10N $l10n,
								Parser $wopiParser,
								IManager $shareManager,
								TokenManager $tokenManager,
								IRootFolder $rootFolder,
								ISession $session,
								$UserId) {
		parent::__construct($appName, $request);
		$this->uid = $UserId;
		$this->l10n = $l10n;
		$this->settings = $settings;
		$this->appConfig = $appConfig;
		$this->wopiParser = $wopiParser;
		$this->shareManager = $shareManager;
		$this->tokenManager = $tokenManager;
		$this->rootFolder = $rootFolder;
		$this->session = $session;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $fileId
	 * @return TemplateResponse
	 */
	public function index($fileId) {
		try {
			$folder = $this->rootFolder->getUserFolder($this->uid);
			$item = $folder->getById($fileId)[0];
			if(!($item instanceof Node)) {
				throw new \Exception();
			}
			list($urlSrc, $token) = $this->tokenManager->getToken($item->getId());
			$params = [
				'permissions' => $item->getPermissions(),
				'title' => $item->getName(),
				'fileId' => $item->getId() . '_' . $this->settings->getSystemValue('instanceid'),
				'token' => $token,
				'urlsrc' => $urlSrc,
				'path' => '/',
				'instanceId' => $this->settings->getSystemValue('instanceid'),
			];

			$response = new TemplateResponse('richdocuments', 'documents', $params, 'empty');
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedFrameDomain($this->appConfig->getAppValue('wopi_url'));
			$policy->allowInlineScript(true);
			$response->setContentSecurityPolicy($policy);
			return $response;
		} catch (\Exception $e) {
		}

		return new TemplateResponse('core', '403', [], 'guest');
	}

	/**
	 * @PublicPage
	 *
	 * @param string $shareToken
	 * @param string $fileName
	 * @return TemplateResponse
	 * @throws \Exception
	 */
	public function publicPage($shareToken, $fileName) {
		try {
			$share = $this->shareManager->getShareByToken($shareToken);
			// not authenticated ?
			if($share->getPassword()){
				if (!$this->session->exists('public_link_authenticated')
					|| $this->session->get('public_link_authenticated') !== (string)$share->getId()
				) {
					throw new \Exception('Invalid password');
				}
			}

			$node = $share->getNode();
			if($node instanceof Folder) {
				$item = $node->get($fileName);
			} else {
				$item = $node;
			}
			if ($item instanceof Node) {
				list($urlSrc, $token) = $this->tokenManager->getToken($item->getId(), $shareToken);
				$params = [
					'permissions' => $share->getPermissions(),
					'title' => $item->getName(),
					'fileId' => $item->getId() . '_' . $this->settings->getSystemValue('instanceid'),
					'token' => $token,
					'urlsrc' => $urlSrc,
					'path' => '/',
					'instanceId' => $this->settings->getSystemValue('instanceid'),
				];

				$response = new TemplateResponse('richdocuments', 'documents', $params, 'empty');
				$policy = new ContentSecurityPolicy();
				$policy->addAllowedFrameDomain($this->appConfig->getAppValue('wopi_url'));
				$policy->allowInlineScript(true);
				$response->setContentSecurityPolicy($policy);
				return $response;
			}
		} catch (\Exception $e) {
		}

		return new TemplateResponse('core', '403', [], 'guest');
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $mimetype
	 * @param string $filename
	 * @param string $dir
	 * @return JSONResponse
	 */
	public function create($mimetype,
						   $filename,
						   $dir){

		$view = new View('/' . $this->uid . '/files');
		if (!$dir){
			$dir = '/';
		}

		$basename = $this->l10n->t('New Document.odt');
		switch ($mimetype) {
			case 'application/vnd.oasis.opendocument.spreadsheet':
				$basename = $this->l10n->t('New Spreadsheet.ods');
				break;
			case 'application/vnd.oasis.opendocument.presentation':
				$basename = $this->l10n->t('New Presentation.odp');
				break;
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$basename = $this->l10n->t('New Document.docx');
				break;
			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
				$basename = $this->l10n->t('New Spreadsheet.xlsx');
				break;
			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
				$basename = $this->l10n->t('New Presentation.pptx');
				break;
			default:
				// to be safe
				$mimetype = 'application/vnd.oasis.opendocument.text';
				break;
		}

		if (!$filename){
			$path = Helper::getNewFileName($view, $dir . '/' . $basename);
		} else {
			$path = $dir . '/' . $filename;
		}

		$content = '';
		if (class_exists('\OC\Files\Type\TemplateManager')){
			$manager = \OC_Helper::getFileTemplateManager();
			$content = $manager->getTemplate($mimetype);
		}

		if (!$content){
			$content = file_get_contents(dirname(__DIR__) . self::ODT_TEMPLATE_PATH);
		}

		if ($content && $view->file_put_contents($path, $content)) {
			$info = $view->getFileInfo($path);
			$ret = $this->wopiParser->getUrlSrc($mimetype);
			$response =  array(
				'status' => 'success',
				'fileid' => $info['fileid'] . '_' . $this->settings->getSystemValue('instanceid'),
				'urlsrc' => $ret['urlsrc'],
				'action' => $ret['action'],
				'lolang' => $this->settings->getUserValue($this->uid, 'core', 'lang', 'en'),
				'data' => \OCA\Files\Helper::formatFileInfo($info)
			);
		} else {
			$response =  array(
				'status' => 'error',
				'message' => (string) $this->l10n->t('Can\'t create document')
			);
		}

		return $response;
	}
}
