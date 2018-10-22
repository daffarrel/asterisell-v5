<?php


/**
 * This class defines the structure of the 'ar_holiday' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 *
 * @package    lib.model.map
 */
class ArHolidayTableMap extends TableMap {

	/**
	 * The (dot-path) name of this class
	 */
	const CLASS_NAME = 'lib.model.map.ArHolidayTableMap';

	/**
	 * Initialize the table attributes, columns and validators
	 * Relations are not initialized by this method since they are lazy loaded
	 *
	 * @return     void
	 * @throws     PropelException
	 */
	public function initialize()
	{
	  // attributes
		$this->setName('ar_holiday');
		$this->setPhpName('ArHoliday');
		$this->setClassname('ArHoliday');
		$this->setPackage('lib.model');
		$this->setUseIdGenerator(true);
		// columns
		$this->addPrimaryKey('ID', 'Id', 'INTEGER', true, null, null);
		$this->addColumn('DAY_OF_MONTH', 'DayOfMonth', 'INTEGER', false, null, null);
		$this->addColumn('MONTH', 'Month', 'INTEGER', false, null, null);
		$this->addColumn('YEAR', 'Year', 'INTEGER', false, null, null);
		$this->addColumn('DAY_OF_WEEK', 'DayOfWeek', 'INTEGER', false, null, null);
		$this->addColumn('FROM_HOUR', 'FromHour', 'INTEGER', false, null, null);
		$this->addColumn('FROM_MINUTES', 'FromMinutes', 'INTEGER', false, null, null);
		$this->addColumn('TO_HOUR', 'ToHour', 'INTEGER', false, null, null);
		$this->addColumn('TO_MINUTES', 'ToMinutes', 'INTEGER', false, null, null);
		$this->addColumn('PEAK_CODE', 'PeakCode', 'VARCHAR', true, 255, null);
		$this->addColumn('NAME', 'Name', 'VARCHAR', false, 1024, null);
		// validators
	} // initialize()

	/**
	 * Build the RelationMap objects for this table relationships
	 */
	public function buildRelations()
	{
	} // buildRelations()

	/**
	 * 
	 * Gets the list of behaviors registered for this table
	 * 
	 * @return array Associative array (name => parameters) of behaviors
	 */
	public function getBehaviors()
	{
		return array(
			'symfony' => array('form' => 'true', 'filter' => 'true', ),
		);
	} // getBehaviors()

} // ArHolidayTableMap
