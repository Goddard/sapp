<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace ddn\sapp;

use ddn\sapp\PDFBaseDoc;
use ddn\sapp\PDFBaseObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;
use ddn\sapp\helpers\Buffer;

use function ddn\sapp\helpers\get_random_string;
use function ddn\sapp\helpers\p_debug;
use function ddn\sapp\helpers\p_debug_var;
use function ddn\sapp\helpers\_add_image;

// Loading the functions
use ddn\sapp\helpers\LoadHelpers;
if (!defined("ddn\\sapp\\helpers\\LoadHelpers"))
    new LoadHelpers;

if (!defined('__TMP_FOLDER'))
    define('__TMP_FOLDER', '/tmp');

// TODO: move the signature of documents to a new class (i.e. PDFDocSignable)

class PDFDoc extends PDFBaseDoc {

    // The PDF version of the parsed file
    protected $_pdf_objects = [];
    protected $_pdf_version_string = null;
    protected $_pdf_trailer_object = null;
    protected $_xref_position = 0;
    protected $_xref_table = [];
    protected $_max_oid = 0;
    protected $_buffer = "";    
    protected $_signature = null;

    // Array of pages ordered by appearance in the final doc (i.e. index 0 is the first page rendered; index 1 is the second page rendered, etc.)
    // Each entry is an array with the following fields:
    //  - id: the id in the document (oid); can be translated into <id> 0 R for references
    //  - info: an array with information about the page
    //      - size: the size of the page
    protected $_pages_info = [];

    public function get_page_count() {
        return count($this->_pages_info);
    }

    /**
     * The function parses a document from a string: analyzes the structure and obtains and object
     *   of type PDFDoc (if possible), or false, if an error happens.
     * @param buffer a string that contains the file to analyze
     */
    public static function from_string($buffer) {
        $structure = self::acquire_structure($buffer);
        if ($structure === false)
            return false;    

        $trailer = $structure["trailer"];
        $version = $structure["version"];
        $xref_table = $structure["xref"];
        $xref_position = $structure["xrefposition"];

        $pdfdoc = new PDFDoc();
        $pdfdoc->_pdf_version_string = $version;
        $pdfdoc->_pdf_trailer_object = $trailer;
        $pdfdoc->_xref_position = $xref_position;
        $pdfdoc->_xref_table = $xref_table;
        $pdfdoc->_xref_table_version = $structure["xrefversion"];
        $pdfdoc->_buffer = $buffer;

        if ($trailer['Encrypt'] !== false)
            p_error("encrypted documents are not fully supported; maybe you cannot get the expected results");

        $oids = array_keys($xref_table);
        sort($oids);
        $pdfdoc->_max_oid = array_pop($oids);

        $pdfdoc->_acquire_pages_info();

        return $pdfdoc;
    }

    /**
     * This function creates an interator over the objects of the document, and makes use of function "get_object".
     *   This mechanism enables to walk over any object, either they are new ones or they were in the original doc.
     *   Enables: 
     *         foreach ($doc->get_object_iterator() as $oid => obj) { ... }
     * @return oid=>obj the objects
     */
    public function get_object_iterator() {
        for ($i = 0; $i < $this->_max_oid; $i++) {
            yield $i => $this->get_object($i);
        }
    }

    /**
     * This function checks whether the passed object is a reference or not, and in case that
     *   it is a reference, it returns the referenced object; otherwise it return the object itself
     * @param reference the reference value to obtain
     * @return obj it reference can be interpreted as a reference, the referenced object; otherwise, the object itself. 
     *   If the passed value is an array of references, it will return false
     */
    public function get_indirect_object( $reference ) {
        $object_id = $reference->get_object_referenced();
        if ($object_id !== false) {
            if (is_array($object_id))
                return false;
            return $this->get_object($object_id);
        }
        return $reference;
    }

    /**
     * Obtains an object from the document, usign the oid in the PDF document.
     * @param oid the oid of the object that is being retrieved
     * @param original if true and the object has been overwritten in this document, the object
     *                 retrieved will be the original one. Setting to false will retrieve the
     *                 more recent object
     * @return obj the object retrieved (or false if not found)
     */
    public function get_object($oid, $original_version = false) {
        if ($original_version === true) {
            // Prioritizing the original version
            $object = self::find_object($this->_buffer, $this->_xref_table, $oid);
            if ($object === false) 
                $object = $this->_pdf_objects[$oid]??false;

        } else {
            // Prioritizing the new versions
            $object = $this->_pdf_objects[$oid]??false;
            if ($object === false)
                $object = self::find_object($this->_buffer, $this->_xref_table, $oid);
        }

        return $object;
    }

    /**
     * This function prepares the document to be generated including a digital signature, using the provided certificate. It is 
     *   possible to set the page in which the signature may appear and the rectangle in which to appear. Moreover, an image can 
     *   be added to make the signature appear. In case that the image is null, the image will be invisible
     * 
     *      LIMITATIONS: one document can be signed once at a time; if wanted more signatures, then chain the documents:
     *      $o1->sign_document(...);
     *      $o2 = PDFDoc::fromstring($o1->to_pdf_file_s);
     *      $o2->sign_document(...);
     *      $o2->to_pdf_file_s();

     * @param certfile the file that contains the certificate to sign the document (format pkcs12)
     * @param certpass the password needed to sign using the certificate
     * @param page_to_appear the page number in which the signature will appear
     * @param rect_to_appear the rectangle (using the page coordinates in pixels) in which the signature will appear
     * @param imagefilename the image to appear, associated to the signature
     * @return prepared true if the file is ready to be genereated with the digital signature
     */
    public function sign_document($certfile, $certpass, $page_to_appear = 0, $rect_to_appear = [ 0, 0, 0, 0 ], $imagefilename = null) {
        // Do not allow more than one signature for a specific document; if needed, signatures must be chained
        if ($this->_signature !== null)
            return p_error("the document has already been prepared to be signed");

        // First we read the certificate
        $certfilecontent = file_get_contents($certfile);
        if ($certfilecontent === false)
            return p_error("could not read file $certfile");
        if (openssl_pkcs12_read($certfilecontent, $certificate, $certpass) === false)
            return p_error("could not get the certificates from file $certfile");
        if ((!isset($certificate['cert'])) || (!isset($certificate['pkey'])))
            return p_error("could not get the certificate or the private key from file $certfile");

        // First of all, we are searching for the root object (which should be in the trailer)
        $root = $this->_pdf_trailer_object["Root"];

        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root_obj = $this->get_object($root);
        if ($root_obj === false)
            return p_error("invalid root object");

        // Now the object corresponding to the page number in which to appear
        $page_obj = $this->get_page($page_to_appear);
        if ($page_obj === false)
            return p_error("invalid page");
    
        // Prepare the signature object (we need references to it)
        $signature = $this->create_object([], "ddn\sapp\PDFSignatureObject");
        $signature->set_certificate($certificate);
        
        // Get the page height, to change the coordinates system (up to down)
        $pagesize = $this->get_page_size($page_to_appear);
        $pagesize_h = floatval("" . $pagesize[3]) - floatval("" . $pagesize[1]);

        // Create the annotation object, annotate the offset and append the object
        $annotation_object = $this->create_object([
                "Type" => "/Annot",
                "Subtype" => "/Widget",
                "FT" => "/Sig",
                "V" => new PDFValueReference($signature->get_oid()),
                "T" => new PDFValueString('Signature' . get_random_string()),
                "P" => new PDFValueReference($page_obj->get_oid()),
                "Rect" => [ $rect_to_appear[0], $pagesize_h - $rect_to_appear[1], $rect_to_appear[2], $pagesize_h - $rect_to_appear[3] ],
                "F" => 132  // TODO: check this value
            ]
        );      
        
        // If an image is provided, let's load it
        if ($imagefilename !== null) {
            // Signature with appearance, following the Adobe workflow: 
            //   1. form
            //   2. layers /n0 (empty) and /n2
            // https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/acrobat_digital_signature_appearances_v9.pdf

            $bbox = [ 0, 0, $rect_to_appear[2] - $rect_to_appear[0], $rect_to_appear[3] - $rect_to_appear[1]];
            $form_object = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Group" => [
                    'Type' => '/Group',
                    'S' => '/Transparency',
                    'CS' => '/DeviceRGB'
                ]
            ]);
    
            $result = _add_image([$this, "create_object"], $imagefilename, ...$bbox);
            if ($result === false)
                return p_error("could not add the image");

            $drawcommand = $result['command'];
            $resources = $result['resources'];

            $layer_n0 = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => new PDFValueObject()
            ]);
            $layer_n0->set_stream("% DSBlank" . __EOL, false);

            $layer_n2 = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => $resources
            ]);
            $layer_n2->set_stream($drawcommand, false);

            $container_form_object = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => [ "XObject" => [
                    "n0" => new PDFValueReference($layer_n0->get_oid()),
                    "n2" => new PDFValueReference($layer_n2->get_oid()) 
                    ] ] 
                ]);
            $container_form_object->set_stream("q 1 0 0 1 0 0 cm /n0 Do Q\nq 1 0 0 1 0 0 cm /n2 Do Q\n", false);

            $form_object['Resources'] = new PDFValueObject([
                "XObject" => [
                    "FRM" => new PDFValueReference($container_form_object->get_oid())
                ]
            ]);
            $form_object->set_stream("/FRM Do", false);

            // Set the signature appearance field to the form object
            $annotation_object["AP"] = [ "N" => new PDFValueReference($form_object->get_oid())];
        }

        // The objects to update
        $updated_objects = [ $annotation_object ];

        // Add the annotation to the page
        if (!isset($page_obj["Annots"]))
            $page_obj["Annots"] = new PDFValueList();

        $annots = &$page_obj["Annots"];

        if ((($referenced = $annots->get_object_referenced()) !== false) && (!is_array($referenced))) {
            // It is an indirect object, so we need to update that object
            $newannots = $this->create_object( 
                $this->get_object($referenced)->get_value()
            );
        } else {
            $newannots = $this->create_object(
                new PDFValueList()
            );
            $newannots->push($annots);
        }

        if (!$newannots->push(new PDFValueReference($annotation_object->get_oid())))
            return p_error("Could not update the page where the signature has to appear");

        $page_obj["Annots"] = new PDFValueReference($newannots->get_oid());
        array_push($updated_objects, $page_obj);

        // AcroForm may be an indirect object
        if (!isset($root_obj["AcroForm"]))
            $root_obj["AcroForm"] = new PDFValueObject();

        $acroform = &$root_obj["AcroForm"];
        if ((($referenced = $acroform->get_object_referenced()) !== false) && (!is_array($referenced))) {
            $acroform = $this->get_object($referenced);
            array_push($updated_objects, $acroform);
        } else {
            array_push($updated_objects, $root_obj);
        }

        // Add the annotation to the interactive form
        $acroform["SigFlags"] = 3;
        if (!isset($acroform['Fields']))
            $acroform['Fields'] = new PDFValueList();

        // Update the xmp metadata if exists
        if (isset($root_obj["Metadata"])) {
            $metadata = $root_obj["Metadata"];
            if ((($referenced = $metadata->get_object_referenced()) !== false) && (!is_array($referenced))) {
                $metadata = $this->get_object($referenced);
                array_push($updated_objects, $metadata);

                $metastream = $metadata->get_stream();
                $metastream = preg_replace('/<xmp:ModifyDate>([^<]*)<\/xmp:ModifyDate>/', '<xmp:ModifyDate>' . (new \DateTime())->format("c") . '</xmp:ModifyDate>', $metastream);
                $metastream = preg_replace('/<xmp:MetadataDate>([^<]*)<\/xmp:MetadataDate>/', '<xmp:MetadataDate>' . (new \DateTime())->format("c") . '</xmp:MetadataDate>', $metastream);
                $metadata->set_stream($metastream, false);
                $this->add_object($metadata);
            }
        }

        // Add the annotation object to the interactive form
        if (!$acroform['Fields']->push(new PDFValueReference($annotation_object->get_oid()))) {
            return p_error("could not create the signature field");
        }

        // Update the information object (not really needed)
        $info = $this->_pdf_trailer_object["Info"];
        if (($info === false) || (($info = $info->get_object_referenced()) === false))
            return p_error("could not find the info object from the trailer");

        $info_obj = $this->get_object($info);
        if ($info_obj === false)
            return p_error("invalid info object");

        $info_obj["ModDate"] = $signature["M"];
        $info_obj["Producer"] = "Modificado con SAPP";
        array_push($updated_objects, $info_obj);

        // Store the objects
        foreach ($updated_objects as &$object) {
            $this->add_object($object);
        }

        // And store the signature
        $this->_signature = $signature;
        return true;
    }

    /**
     * Function that gets the objects that have been read from the document
     * @return objects an array of objects, indexed by the oid of each object
     */
    public function get_objects() {
        return $this->_pdf_objects;
    }

    /**
     * Function that gets the version of the document. It will have the form
     *   PDF-1.x
     * @return version the PDF version
     */
    public function get_version() {
        return $this->_pdf_version_string;
    }

    /**
     * Function that sets the version for the document. 
     * @param version the version of the PDF document (it shall have the form PDF-1.x)
     * @return correct true if the version had the proper form; false otherwise
     */
    public function set_version($version) {
        if (preg_match("/^PDF-1.\[0-9\]$/", $version) !== 1) {
            return false;
        }
        $this->_pdf_version_string = $version;
        return true;
    }

    /**
     * Function that creates a new PDFObject and stores it in the document object list, so that
     *   it is automatically managed by the document. The returned object can be modified and
     *   that modifications will be reflected in the document.
     * @param value the value that the object will contain
     * @return obj the PDFObject created
     */
    public function create_object($value = [], $class = "ddn\sapp\PDFObject"): PDFObject {
        $o = new $class($this->get_new_oid(), $value);
        $this->add_object($o);
        return $o;
    }

    /**
     * Adds a pdf object to the document (overwrites the one with the same oid, if existed)
     * @param pdf_object the object to add to the document
     */
    public function add_object(PDFObject $pdf_object) {
        $oid = $pdf_object->get_oid();
        $this->_pdf_objects[$oid] = $pdf_object;

        // Update the maximum oid
        if ($oid > $this->_max_oid)
            $this->_max_oid = $oid;
    }

    /**
     * This function generates all the contents of the file up to the xref entry. 
     * @param rebuild whether to generate the xref with all the objects in the document (true) or
     *                consider only the new ones (false)
     * @return xref_data [ the text corresponding to the objects, array of offsets for each object ]
     */
    protected function _generate_content_to_xref($rebuild = false) {
        if ($rebuild === true) {
            $result  = new Buffer("%$this->_pdf_version_string" . __EOL);
        }  else {
            $result = new Buffer($this->_buffer);
        }

        // Need to calculate the objects offset
        $offsets = [];
        $offsets[0] = 0;

        // The objects
        $offset = $result->size();

        if ($rebuild === true) {
            for ($i = 0; $i <= $this->_max_oid; $i++) {
                if (($object = $this->get_object($i)) ===  false) continue;

                $result->data($object->to_pdf_entry());    
                $offsets[$i] = $offset;
                $offset = $result->size();
            }
        } else {
            foreach ($this->_pdf_objects as $obj_id => $object) {
                $result->data($object->to_pdf_entry());
                $offsets[$obj_id] = $offset;
                $offset = $result->size();
            }
        }

        return [ $result, $offsets ];
    }

    /**
     * This functions outputs the document to a buffer object, ready to be dumped to a file.
     * @param rebuild whether we are rebuilding the whole xref table or not (in case of incremental versions, we should use "false")
     * @return buffer a buffer that contains a pdf dumpable document
     */
    public function to_pdf_file_b($rebuild = false) : Buffer {
        // We made no updates, so return the original doc
        if (($rebuild === false) && (count($this->_pdf_objects) === 0))
            return new Buffer($this->_buffer);

        // Generate the first part of the document
        [ $_doc_to_xref, $_obj_offsets ] = $this->_generate_content_to_xref($rebuild);
        $xref_offset = $_doc_to_xref->size();

        if ($this->_signature !== null) {
            $_obj_offsets[$this->_signature->get_oid()] = $_doc_to_xref->size();
            $xref_offset +=  strlen($this->_signature->to_pdf_entry());
        }

        $doc_version_string = str_replace("PDF-", "", $this->_pdf_version_string);

        // The version considered for the cross reference table depends on the version of the current xref table,
        //   as it is not possible to mix xref tables. Anyway we are 
        $target_version = $this->_xref_table_version;
        if ($this->_xref_table_version >= "1.5") {
            // i.e. xref streams
            if ($doc_version_string > $target_version)
                $target_version = $doc_version_string;
        } else {
            // i.e. xref+trailer
            if ($doc_version_string < $target_version)
                $target_version = $doc_version_string;
        }

        if ($target_version >= "1.5") {
            p_debug("generating xref using cross-reference streams");

            // Create a new object for the trailer
            $trailer = $this->create_object(
                clone $this->_pdf_trailer_object
            );

            // Add this object to the offset table, to be also considered in the xref table
            $_obj_offsets[$trailer->get_oid()] = $xref_offset;

            // Generate the xref cross-reference stream
            $xref = self::build_xref_1_5($_obj_offsets);

            // Set the parameters for the trailer
            $trailer["Index"] = explode(" ", $xref["Index"]);
            $trailer["W"] = $xref["W"];
            $trailer["Size"] = $this->_max_oid + 1;
            $trailer["Type"] = "/XRef";

            // Not needed to generate new IDs, as in metadata the IDs will be set
            // $ID1 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $xref["stream"]);
            // $ID2 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $this->_pdf_trailer_object);
            // $trailer["ID"] = [ new PDFValueHexString($ID1), new PDFValueHexString($ID2) ];

            // We are not using predictors nor encoding
            if (isset($trailer["DecodeParms"])) unset($trailer["DecodeParms"]);

            // We are not compressing the stream
            if (isset($trailer["Filter"])) unset($trailer["Filter"]);
            $trailer->set_stream($xref["stream"], false);

            // If creating an incremental modification, point to the previous xref table
            if ($rebuild === false)
                $trailer['Prev'] = $this->_xref_position;
            else
                // If rebuilding the document, remove the references to previous xref tables, because it will be only one
                if (isset($trailer['Prev']))
                    unset($trailer['Prev']);

            // And generate the part of the document related to the xref
            $_doc_from_xref = new Buffer($trailer->to_pdf_entry());
            $_doc_from_xref->data("startxref" . __EOL . "$xref_offset" . __EOL ."%%EOF" . __EOL);
        } else {
            p_debug("generating xref using classic xref...trailer");
            $xref_content = self::build_xref($_obj_offsets);

            // Update the trailer
            $this->_pdf_trailer_object['Size'] = $this->_max_oid + 1;

            if ($rebuild === false)
                $this->_pdf_trailer_object['Prev'] = $this->_xref_position;

            // Not needed to generate new IDs, as in metadata the IDs may be set
            // $ID1 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $xref_content);
            // $ID2 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $this->_pdf_trailer_object);
            // $this->_pdf_trailer_object['ID'] = new PDFValueList(
            //    [ new PDFValueHexString($ID1), new PDFValueHexString($ID2) ]
            // );

            // Generate the part of the document related to the xref
            $_doc_from_xref = new Buffer($xref_content);
            $_doc_from_xref->data("trailer\n$this->_pdf_trailer_object");
            $_doc_from_xref->data("\nstartxref\n$xref_offset\n%%EOF\n");
        }

        if ($this->_signature !== null) {
            // In case that the document is signed, calculate the signature

            $this->_signature->set_sizes($_doc_to_xref->size(), $_doc_from_xref->size());
            $this->_signature["Contents"] = new PDFValueSimple("");
            $_signable_document = new Buffer($_doc_to_xref->get_raw() . $this->_signature->to_pdf_entry() . $_doc_from_xref->get_raw());

            // We need to write the content to a temporary folder to use the pkcs7 signature mechanism
            $temp_filename = tempnam(__TMP_FOLDER, 'pdfsign');
            $temp_file = fopen($temp_filename, 'wb');
            fwrite($temp_file, $_signable_document->get_raw());
            fclose($temp_file);

            // Calculate the signature and remove the temporary file
            $certificate = $this->_signature->get_certificate();
            $signature_contents = self::calculate_pkcs7_signature($temp_filename, $certificate['cert'], $certificate['pkey']);
            unlink($temp_filename);

            // Then restore the contents field
            $this->_signature["Contents"] = new PDFValueHexString($signature_contents);

            // Add this object to the content previous to this document xref
            $_doc_to_xref->data($this->_signature->to_pdf_entry());
        }

        return new Buffer($_doc_to_xref->get_raw() . $_doc_from_xref->get_raw());
    }

    /**
     * This functions outputs the document to a string, ready to be written
     * @return buffer a buffer that contains a pdf document
     */
    public function to_pdf_file_s($rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);
        return $pdf_content->get_raw();
    }

    /**
     * This function writes the document to a file
     * @param filename the name of the file to be written (it will be overwritten, if exists)
     * @return written true if the file has been correcly written to the file; false otherwise
     */
    public function to_pdf_file($filename, $rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);

        $file = fopen($filename, "wb");
        if ($file === false) {
            return p_error("failed to create the file");
        }
        if (fwrite($file, $pdf_content->get_raw()) !== $pdf_content->size()) {
            fclose($file);
            return p_error("failed to write to file");
        }
        fclose($file);
        return true;
    }

    /**
     * Gets the page object which is rendered in position i
     * @param i the number of page (according to the rendering order)
     * @return page the page object
     */
    public function get_page($i) {
        if ($i < 0) return false;
        if ($i >= count($this->_pages_info)) return false;
        return $this->get_object($this->_pages_info[$i]['id']);
    }

    /**
     * Gets the size of the page in the form of a rectangle [ x0 y0 x1 y1 ]
     * @param i the number of page (according to the rendering order), or the page object
     * @return box the bounding box of the page
     */
    public function get_page_size($i) {
        $pageinfo = false;
        
        if (is_int($i)) {
            if ($i < 0) return false;
            if ($i > count($this->_pages_info)) return false;

            $pageinfo = $this->_pages_info[$i]['info'];
        } else {
            foreach ($this->_pages_info as $k => $info) {
                if ($info['oid'] === $i->get_oid()) {
                    $pageinfo = $info['info'];
                    break;
                }
            }
        }

        // The page has not been found
        if (($pageinfo === false) || (!isset($pageinfo['size'])))
            return false;

        return $pageinfo['size'];
    }

    /**
     * This function builds the page IDs for object with id oid. If it is a page, it returns the oid; if it is not and it has 
     *   kids and every kid is a page (or a set of pages), it finds the pages.
     * @param oid the object id to inspect
     * @return pages the ordered list of page ids corresponding to object oid, or false if any of the kid objects
     *               is not of type page or pages.
     */
    protected function _get_page_info($oid, $info = []) {
        $object = $this->get_object($oid);
        if ($object === false)
            return p_error("could not get information about the page");

        $page_ids = [];

        switch ($object["Type"]->val()) {
            case "Pages":
                $kids = $object["Kids"];
                $kids = $kids->get_object_referenced();
                if ($kids !== false) {
                    if (isset($object['MediaBox'])) {
                        $info['size'] = $object['MediaBox']->val();
                    }
                    foreach ($kids as $kid) {
                        $ids = $this->_get_page_info($kid, $info);
                        if ($ids === false)
                            return false;
                        array_push($page_ids, ...$ids);
                    }
                } else {
                    return p_error("could not get the pages");
                }
                break;
            case "Page":
                if (isset($object['MediaBox']))
                    $info['size'] = $object['MediaBox']->val();
                return [ [ 'id' => $oid, 'info' => $info ]  ];
            default:
                return false;
        }
        return $page_ids;
    }

    /**
     * Obtains an ordered list of objects that contain the ids of the page objects of the document.
     *   The order is made according to the catalog and the document structure.
     * @return list an ordered list of the id of the page objects, or false if could not be found
     */
    protected function _acquire_pages_info() {
        $root = $this->_pdf_trailer_object["Root"];
        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root = $this->get_object($root);
        $pages = $root["Pages"];
        if (($pages === false) || (($pages = $pages->get_object_referenced()) === false))
            return p_error("could not find the pages for the document");
        
        $this->_pages_info = $this->_get_page_info($pages);
    }    
}
