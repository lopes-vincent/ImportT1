<?php


namespace ImportT1\Import;


use ImportT1\Model\Db;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Log\Tlog;
use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;

class UrlImport extends BaseImport
{
    protected $category_corresp;

    protected $product_corresp;

    protected $content_corresp;

    protected $folder_corresp;

    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db)
    {
        parent::__construct($dispatcher, $t1db);

        $this->product_corresp = new CorrespondanceTable(CorrespondanceTable::PRODUCTS, $this->t1db);
        $this->category_corresp = new CorrespondanceTable(CorrespondanceTable::CATEGORIES, $this->t1db);
        $this->content_corresp = new CorrespondanceTable(CorrespondanceTable::CONTENTS, $this->t1db);
        $this->folder_corresp = new CorrespondanceTable(CorrespondanceTable::FOLDERS, $this->t1db);
    }
    public function getChunkSize()
    {
        return 100;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from reecriture WHERE reecriture.actif = 1"));
    }

    public function import($startRecord = 0)
    {
        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
            ->query(
                sprintf(
                    "SELECT * FROM reecriture 
                    WHERE reecriture.actif = 1
                    ORDER BY id ASC limit %d, %d",
                    intval($startRecord),
                    $this->getChunkSize()
                )
            );

        while ($hdl && $reecriture = $this->t1db->fetch_object($hdl)) {
            $count++;

            $view = $this->findView($reecriture);

            if (null == $view) {
                $errors++;
                Tlog::getInstance()->addError("Failed to import url ID=$reecriture->id view  $reecriture->fond not found");
                continue;
            }

            try {
                $viewId = $this->findViewId($reecriture);

                if (null == $viewId) {
                    throw new \Exception();
                }
            } catch (\Exception $e) {
                $errors++;
                Tlog::getInstance()->addError("Failed to import url ID=$reecriture->id view_id not found");
                continue;
            }

            $lang = $this->getT2Lang($reecriture->lang);

            if ($lang == null) {
                $errors++;
                Tlog::getInstance()->addError("Failed to import url ID=$reecriture->id locale not found");
                continue;
            }

            $viewLocale = $lang->getLocale();

            $existingRewrite = RewritingUrlQuery::create()
                ->filterByUrl($reecriture->url)
                ->findOne();

            if (null !== $existingRewrite) {
                continue;
            }

            $hasDefaultRewrite = RewritingUrlQuery::create()
                ->filterByView($view)
                ->filterByViewId($viewId)
                ->filterByViewLocale($viewLocale)
                ->filterByRedirected(null)
                ->findOne();

            $rewritingUrl = new RewritingUrl();
            $rewritingUrl->setUrl($reecriture->url)
                ->setView($view)
                ->setViewId($viewId)
                ->setViewLocale($viewLocale);

            if (null !== $hasDefaultRewrite) {
                $rewritingUrl->setRedirected($rewritingUrl->getId());
            }

            $rewritingUrl->save();
        }

        return new ImportChunkResult($count, $errors);
    }

    protected function findView($reecriture)
    {
        $correspondanceArray = [
            'rubrique' => 'category',
            'produit' => 'product',
            'contenu' => 'content',
            'dossier' => 'folder'
        ];

        if (isset($correspondanceArray[$reecriture->fond])) {
            return $correspondanceArray[$reecriture->fond];
        }

        return null;
    }

    protected function findViewId($reecriture)
    {
        $t1View = $reecriture->fond;
        $viewId = null;

        parse_str($reecriture->param, $params);

        if (isset($params["id_$t1View"])) {
            $viewId = $params["id_$t1View"];
        }

        $t2ViewId = null;

        if (null != $viewId) {
            switch ($t1View) {
                case 'rubrique':
                    $t2ViewId = $this->category_corresp->getT2(intval($viewId));
                    break;
                case 'produit':
                    $t2ViewId = $this->product_corresp->getT2(intval($viewId));
                    break;
                case 'contenu':
                    $t2ViewId = $this->content_corresp->getT2(intval($viewId));
                    break;
                case 'dossier':
                    $t2ViewId = $this->folder_corresp->getT2(intval($viewId));
                    break;
                default:
                    return null;
            }
        }

        return $t2ViewId;
    }
}
