<?php
namespace ImportT1\Import;

use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Log\Tlog;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ImportT1\Model\Db;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductDocument;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use ImportT1\Import\Media\ProductDocumentImport;
use ImportT1\Import\Media\ProductImageImport;
use Thelia\Core\Event\Product\ProductAddContentEvent;
use Thelia\Core\Event\Product\ProductAddAccessoryEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Model\TaxQuery;
use Thelia\Action\TaxRule;
use Thelia\Model\TaxRuleQuery;
use Thelia\Core\Event\Tax\TaxEvent;
use Thelia\Core\Event\Tax\TaxRuleEvent;
use Thelia\Model\LangQuery;
use Thelia\Model\TaxI18nQuery;
use Thelia\TaxEngine\TaxType\PricePercentTaxType;
use Thelia\Model\CountryQuery;
use Thelia\Action\ProductSaleElement;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\ProductPriceQuery;

class ProductsImport extends BaseImport
{
    private $product_corresp, $tpl_corresp, $tax_corresp;


    public function __construct(EventDispatcherInterface $dispatcher, Db $t1db) {

        parent::__construct($dispatcher, $t1db);

        $this->product_corresp  = new CorrespondanceTable(CorrespondanceTable::PRODUCTS, $this->t1db);

        $this->cat_corresp  = new CorrespondanceTable(CorrespondanceTable::CATEGORIES, $this->t1db);
        $this->tpl_corresp  = new CorrespondanceTable(CorrespondanceTable::TEMPLATES, $this->t1db);
        $this->tax_corresp  = new CorrespondanceTable(CorrespondanceTable::TAX, $this->t1db);
    }

    public function getChunkSize() {
        return 10;
    }

    public function getTotalCount()
    {
        return $this->t1db->num_rows($this->t1db->query("select id from produit"));
    }

    public function preImport()
    {
        // Delete table before proceeding
        ProductQuery::create()->deleteAll();

        ProductImageQuery::create()->deleteAll();
        ProductDocumentQuery::create()->deleteAll();

        TaxRuleQuery::create()->deleteAll();
        TaxQuery::create()->deleteAll();

        ProductSaleElementsQuery::create()->deleteAll();
        ProductPriceQuery::create()->deleteAll();

        // Create T1 <-> T2 IDs correspondance tables
        $this->product_corresp->reset();
        $this->tax_corresp->reset();

        // Importer les taxes
        $this->importTaxes();
    }

    public function import($startRecord = 0)
    {
        $count = 0;

        $errors = 0;

        $hdl = $this->t1db
                ->query(
                        sprintf("select * from produit order by rubrique asc limit %d, %d", intval($startRecord),
                                $this->getChunkSize()));

        $image_import    = new ProductImageImport($this->dispatcher, $this->t1db);
        $document_import = new ProductDocumentImport($this->dispatcher, $this->t1db);

        while ($hdl && $produit = $this->t1db->fetch_object($hdl)) {

            $count++;

            $rubrique = $this->cat_corresp->getT2($produit->rubrique);

            if ($rubrique > 0) {

                try {
                    $this->product_corresp->getT2($produit->id);

                    Tlog::getInstance()->warning("Product ID=$produit->id already imported.");

                    continue;
                }
                catch (ImportException $ex) {
                    // Okay, the product was not imported.
                }

                try {

                    $event = new ProductCreateEvent();

                    $idx = 0;

                    $descs = $this->t1db
                            ->query_list("select * from produitdesc where produit = ? order by lang asc", array(
                                $produit->id
                            ));

                    foreach ($descs as $objdesc) {

                        $lang = $this->getT2Lang($objdesc->lang);

                        // A title is required to create the rewritten URL
                        if (empty($objdesc->titre)) $objdesc->titre = sprintf("Untitled-%d-%s", $objdesc->id, $lang->getCode());

                        if ($idx == 0) {

                            $event
                                ->setRef($produit->ref)
                                ->setLocale($lang->getLocale())
                                ->setTitle($objdesc->titre)
                                ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique))
                                ->setVisible($produit->ligne == 1 ? true : false)
                                ->setBasePrice($produit->prix)
                                ->setBaseWeight($produit->poids)
                                ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                                ->setCurrencyId($this->getT2Currency()->getId())
                            ;
echo "<br />create<br />";
                            $this->dispatcher->dispatch(TheliaEvents::PRODUCT_CREATE, $event);
echo "create ok<br />";

                            $product_id = $event->getProduct()->getId();

                            // Update position
                            // ---------------

                            $update_position_event = new UpdatePositionEvent($product_id,
                                    UpdatePositionEvent::POSITION_ABSOLUTE, $produit->classement);

                            $this->dispatcher->dispatch(TheliaEvents::PRODUCT_UPDATE_POSITION, $update_position_event);

                            Tlog::getInstance()->info("Created product $product_id from $objdesc->titre ($produit->id)");

                            $this->product_corresp->addEntry($produit->id, $product_id);

                            // Import related content
                            // ----------------------
                            $contents = $this->t1db->query_list(
                                    "select * from contenuassoc where objet=? and type=1 order by classement", array($produit->id)); // type: 1 = produit, 0=rubrique

                            foreach($contents as $content) {

                                try {
                                    $content_event = new ProductAddContentEvent($event->getProduct(), $this->content_corresp->getT2($content->contenu));

                                    $this->dispatcher->dispatch(TheliaEvents::PRODUCT_ADD_CONTENT, $content_event);
                                }
                                catch (\Exception $ex) {
                                    Tlog::getInstance()
                                        ->addError(
                                            "Failed to create associated content $content->contenu for product $product_id: ",
                                            $ex->getMessage());

                                    $errors++;
                                }
                            }
                            echo "rel done<br />";
                            // Set the product template
                            // ------------------------

                            try {
                                $pste = new ProductSetTemplateEvent(
                                        $event->getProduct(),
                                        $this->tpl_corresp->getT2($produit->rubrique),
                                        $this->getT2Currency()->getId()
                                 );

                                $this->dispatcher->dispatch(TheliaEvents::PRODUCT_SET_TEMPLATE, $pste);
                            }
                            catch (ImportException $ex) {
                                Tlog::getInstance()
                                    ->addWarning(
                                        "No product template was found for product $product_id: ",
                                        $ex->getMessage());
                            }
                            echo "tpl done<br />";
                            // Import images and documents
                            // ---------------------------

                            $image_import->importMedia($produit->id, $product_id);
                            $document_import->importMedia($produit->id, $product_id);

                            // Update the rewritten URL, if one was defined
                            $this->updateRewrittenUrl($event->getProduct(), $lang->getLocale(), $objdesc->lang, "produit", "id_produit=$produit->id");
                        }

                        // Update the newly created product
                        $update_event = new ProductUpdateEvent($product_id);

                        $update_event
                            ->setRef($produit->ref)
                            ->setLocale($lang->getLocale())
                            ->setTitle($objdesc->titre)
                            ->setDefaultCategory($this->cat_corresp->getT2($produit->rubrique))
                            ->setVisible($produit->ligne == 1 ? true : false)
                            ->setBasePrice($produit->prix)
                            ->setBaseWeight($produit->poids)
                            ->setTaxRuleId($this->tax_corresp->getT2(1000 * $produit->tva))
                            ->setCurrencyId($this->getT2Currency()->getId())
                            ->setChapo($objdesc->chapo)
                            ->setDescription($objdesc->description)
                            ->setPostscriptum($objdesc->postscriptum)
                        ;
echo "update !<br />";
                        $this->dispatcher->dispatch(TheliaEvents::PRODUCT_UPDATE, $update_event);
echo "update OK !<br />";
                        $idx++;
                    }

                    echo "TODO: import valeurs de carac et declis, + combinaisons.<br />";
                }
                catch (ImportException $ex) {

                    Tlog::getInstance()->addError("Failed to create product ID=$produit->id: ", $ex->getMessage());

                    $errors++;
                }
            }
            else {
                Tlog::getInstance()->addError("Cannot import product ID=$produit->id, which is at root level (e.g., rubrique parent = 0).");

                $errors++;
            }
        }

        return new ImportChunkResult($count, $errors);
    }

    public function importTaxes()
    {
        $taux_tvas = $this->t1db->query_list("select distinct tva from produit");

        $langs = LangQuery::create()->find();

        $defaultCountry = CountryQuery::create()->findOneByByDefault(true);

        foreach($taux_tvas as $taux_tva) {

            $ppe = new PricePercentTaxType();

            $ppe->setPercentage($taux_tva->tva);

            $taxEvent = new TaxEvent();

            $taxEvent
                ->setLocale($langs[0]->getLocale())
                ->setTitle("TVA $taux_tva->tva%")
                ->setDescription("This tax was imported from Thelia 1 using TVA $taux_tva->tva%")
                ->setType(get_class($ppe))
                ->setRequirements($ppe->getRequirements())
            ;

            $this->dispatcher->dispatch(TheliaEvents::TAX_CREATE, $taxEvent);

            $taxEvent->setId($taxEvent->getTax()->getId());

            Tlog::getInstance()->info("Created tax ID=".$taxEvent->getTax()->getId()." for TVA $taux_tva->tva");

            for($idx = 1; $idx < count($langs); $idx++) {
                $taxEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("TVA $taux_tva->tva%")
                ;

                $this->dispatcher->dispatch(TheliaEvents::TAX_UPDATE, $taxEvent);
            }

            $taxRuleEvent = new TaxRuleEvent();

            $taxRuleEvent
                ->setLocale($langs[0]->getLocale())
                ->setTitle("Tax rule for TVA $taux_tva->tva%")
                ->setDescription("This tax rule was created from Thelia 1 using TVA $taux_tva->tva%")
                ->setCountryList(array($defaultCountry->getId()))
                ->setTaxList(json_encode(array($taxEvent->getTax()->getId())))
            ;

            $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_CREATE, $taxRuleEvent);

            $taxRuleEvent->setId($taxRuleEvent->getTaxRule()->getId());

            $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_TAXES_UPDATE, $taxRuleEvent);

            Tlog::getInstance()->info("Created tax rule ID=".$taxRuleEvent->getTaxRule()->getId()." for TVA $taux_tva->tva");

            for($idx = 1; $idx < count($langs); $idx++) {
                $taxRuleEvent
                    ->setLocale($langs[$idx]->getLocale())
                    ->setTitle("Tax rule for TVA $taux_tva->tva%")
                ;

                $this->dispatcher->dispatch(TheliaEvents::TAX_RULE_UPDATE, $taxRuleEvent);
            }

            $this->tax_corresp->addEntry(1000 * $taux_tva->tva, $taxRuleEvent->getTaxRule()->getId());
        }
    }

    public function postImport() {

        // Import product Accessories
        // --------------------------

        $accessoires = $this->t1db->query_list(
                "select * from accessoire order by classement");

        foreach($accessoires as $accessoire) {

            try {
                $accessory_event = new ProductAddAccessoryEvent(
                    $this->product_corresp->getT2($accessoire->produit),
                    $this->product_corresp->getT2($accessoire->accessoire)
                 );

                $this->dispatcher->dispatch(TheliaEvents::PRODUCT_ADD_ACCESSORY, $accessory_event);
            }
            catch (\Exception $ex) {
                Tlog::getInstance()
                    ->addError(
                        "Failed to create product accessory $accessoire->id: ",
                        $ex->getMessage());
            }
        }
    }
}