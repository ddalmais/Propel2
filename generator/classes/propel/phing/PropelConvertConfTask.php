<?php

/*
 *  $Id: PropelDataDTDTask.php 445 2006-09-22 20:37:47Z hans $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://propel.phpdb.org>.
 */

require_once 'phing/Task.php';
require_once 'propel/phing/PropelDataModelTemplateTask.php';
require_once 'propel/engine/builder/om/OMBuilder.php';
include_once 'propel/engine/builder/om/ClassTools.php';

/**
 * This Task converts the XML runtime configuration file into a PHP array for faster performance.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 * @package    propel.phing
 */
class PropelConvertConfTask extends AbstractPropelDataModelTask {

	/**
	 * @var        PhingFile The XML runtime configuration file to be converted.
	 */
	private $xmlConfFile;

	/**
	 * @var        PhingFile This is the file where the converted conf array dump will be placed.
	 */
	private $outputFile;

	/**
	 * [REQUIRED] Set the input XML runtime conf file.
	 * @param      PhingFile $v The XML runtime configuration file to be converted.
	 */
	public function setXmlConfFile(PhingFile $v)
	{
		$this->xmlConfFile = $v;
	}

	/**
	 * [REQUIRED] Set the output filename for the converted runtime conf.
	 * The directory is specified using AbstractPropelDataModelTask#setOutputDirectory().
	 * @param      string $outputFile
	 * @see        AbstractPropelDataModelTask#setOutputDirectory()
	 */
	public function setOutputFile($outputFile)
	{
		// this is a string, not a file
		$this->outputFile = $outputFile;
	}

	/**
	 * The main method does the work of the task.
	 */
	public function main()
	{
		// Check to make sure the input and output files were specified and that the input file exists.

		if (!$this->xmlConfFile || !$this->xmlConfFile->exists()) {
			throw new BuildException("No valid xmlConfFile specified.", $this->getLocation());
		}

		if (!$this->outputFile) {
			throw new BuildException("No outputFile specified.", $this->getLocation());
		}

		// Create a PHP array from the XML file

		$xmlDom = new DOMDocument();
		$xmlDom->load($this->xmlConfFile->getAbsolutePath());
		$xml = simplexml_load_string($xmlDom->saveXML());

		$phpconf = self::simpleXmlToArray($xml);

		// $this->log(var_export($phpconf,true));

		// Create a map of all PHP classes and their filepaths for this data model

		DataModelBuilder::setBuildProperties($this->getPropelProperties());

		foreach ($this->getDataModels() as $dataModel) {

			foreach ($dataModel->getDatabases() as $database) {

				$classMap = array();

				// $this->log("Processing class mappings in database: " . $database->getName());

		//print the tables
				foreach($database->getTables() as $table) {

					if (!$table->isForReferenceOnly()) {

						// $this->log("\t+ " . $table->getName());

						// Classes that I'm assuming do not need to be mapped (because they will be required by subclasses):
						//	- base peer and object classes
						//	- interfaces
						//	- base node peer and object classes

						// -----------------------------------------------------------------------------------------
						// Add Peer & Object stub classes and MapBuilder classe
						// -----------------------------------------------------------------------------------------
						// (this code is based on PropelOMTask)

						foreach(array('mapbuilder', 'peerstub', 'objectstub') as $target) {
							$builder = DataModelBuilder::builderFactory($table, $target);
							$this->log("Adding class mapping: " . $builder->getClassname() . ' => ' . $builder->getClassFilePath());
							$classMap[$builder->getClassname()] = $builder->getClassFilePath();
						}

						if ($table->getChildrenColumn()) {
							$col = $table->getChildrenColumn();
							if ($col->isEnumeratedClasses()) {
								foreach ($col->getChildren() as $child) {
									$builder = DataModelBuilder::builderFactory($table, 'objectmultiextend');
									$builder->setChild($child);
									$this->log("Adding class mapping: " . $builder->getClassname() . ' => ' . $builder->getClassFilePath());
									$classMap[$builder->getClassname()] = $builder->getClassFilePath();
								}
							}
						}

						$baseClass = $table->getBaseClass();
						if ( $baseClass !== null ) {
							$className = ClassTools::classname($baseClass);
							if (!isset($classMap[$className])) {
								$classPath = ClassTools::getFilePath($baseClass);
								$this->log('Adding class mapping: ' . $className . ' => ' . $classPath);
								$classMap[$className] = $classPath;
							}
						}

						// -----------------------------------------------------------------------------------------
						// Create tree Node classes
						// -----------------------------------------------------------------------------------------

						if ($table->isTree()) {
							foreach(array('nodepeerstub', 'nodestub') as $target) {
								$builder = DataModelBuilder::builderFactory($table, $target);
								$this->log("Adding class mapping: " . $builder->getClassname() . ' => ' . $builder->getClassFilePath());
								$classMap[$builder->getClassname()] = $builder->getClassFilePath();
							}
						} // if Table->isTree()

					} // if (!$table->isReferenceOnly())
				}

				$phpconf['propel']['datasources'][$database->getName()]['classes'] = $classMap;
			}
		}

//		$phpconf['propel']['classes'] = $classMap;

		$phpconf['propel']['generator_version'] = DataModelBuilder::getBuildProperty('version');

		// Write resulting PHP data to output file:

		$outfile = new PhingFile($this->outputDirectory, $this->outputFile);

		$output = '<' . '?' . "php\n";
		$output .= "// This file generated by Propel " . $phpconf['propel']['generator_version'] . " convert-props target on " . strftime("%c") . "\n";
		$output .= "// from XML runtime conf file " . $this->xmlConfFile->getPath() . "\n";
 		$output .= "return ";
 		$output .= var_export($phpconf, true);
 		$output .= ";";

 		$this->log("Creating PHP runtime conf file: " . $outfile->getPath());

 		if (!file_put_contents($outfile->getAbsolutePath(), $output)) {
 			throw new BuildException("Error creating output file: " . $outfile->getAbsolutePath(), $this->getLocation());
 		}

	} // main()

	/**
	 * Recursive function that converts an SimpleXML object into an array.
	 * @author     Christophe VG (based on code form php.net manual comment)
	 * @param      object SimpleXML object.
	 * @return     array Array representation of SimpleXML object.
	 */
	private static function simpleXmlToArray($xml)
	{
		$ar = array();

		foreach( $xml->children() as $k => $v ) {

			// recurse the child
			$child = self::simpleXmlToArray( $v );

			//print "Recursed down and found: " . var_export($child, true) . "\n";

			// if it's not an array, then it was empty, thus a value/string
			if ( count($child) == 0 ) {
				$child = self::getConvertedXmlValue($v);

			}

			// add the childs attributes as if they where children
			foreach( $v->attributes() as $ak => $av ) {

				// if the child is not an array, transform it into one
				if ( !is_array( $child ) ) {
					$child = array( "value" => $child );
				}

				if ($ak == 'id') {
					// special exception: if there is a key named 'id'
					// then we will name the current key after that id
					$k = self::getConvertedXmlValue($av);
				} else {
					// otherwise, just add the attribute like a child element
					$child[$ak] = self::getConvertedXmlValue($av);
				}
			}

			 // if the $k is already in our children list, we need to transform
			 // it into an array, else we add it as a value
			 if ( !in_array( $k, array_keys($ar) ) ) {
				 $ar[$k] = $child;
			 } else {
				 // if the $ar[$k] element is not already an array, then we need to make it one
				 if ( !is_array( $ar[$k] ) ) { $ar[$k] = array( $ar[$k] ); }
				 $ar[$k][] = $child;
			 }

		}

		return $ar;
	}

 	/**
 	 * Process XML value, handling boolean, if appropriate.
 	 * @param      object The simplexml value object.
 	 * @return     mixed
 	 */
	private static function getConvertedXmlValue($value)
	{
		$value = (string) $value; // convert from simplexml to string
		// handle booleans specially
		$lwr = strtolower($value);
		if ($lwr === "false") {
			$value = false;
		} elseif ($lwr === "true") {
			$value = true;
		}
		return $value;
	}
}