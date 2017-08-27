<?php
/**
 * The LinkTitles\Targets class.
 *
 * Copyright 2012-2017 Daniel Kraus <bovender@bovender.de> ('bovender')
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @author Daniel Kraus <bovender@bovender.de>
 */
namespace LinkTitles;

/**
 * Fetches potential target page titles from the database.
 */
class Targets {
	private static $instance;

	/**
	 * Singleton factory that returns a (cached) database query results with
	 * potential target page titles.
	 *
	 * The subset of pages that may serve as target pages depends on the
	 * name space of the source page. Therefore, if the $nameSpace differs from
	 * the cached name space, the database is queried again.
	 *
	 * @param  String $nameSpace The namespace of the current page.
	 * @param  Config $config    LinkTitles configuration.
	 */
	public static function default( \Title $title, Config $config ) {
		if ( ( self::$instance === null ) || ( self::$instance->nameSpace != $title->getNamespace() ) ) {
			self::$instance = new Targets( $title, $config );
		}
		return self::$instance;
	}

	/**
	 * Invalidates the cache; the next call of Targets::default() will trigger
	 * a database query.
	 *
	 * Use this in unit tests which are performed in a single request cycle so that
	 * changes to the pages list may not be picked up by the cached Targets instance.
	 */
	public static function invalidate() {
		self::$instance = null;
	}

	/**
	 * Holds the results of a database query for target page titles, filtered
	 * and sorted.
	 * @var IResultWrapper $queryResult
	 */
	public $queryResult;

	/**
	 * Holds the name space (integer) for which the list of target pages was built.
	 * @var Int $nameSpace
	 */
	public $nameSpace;

	private $config;

	/**
	 * The constructor is private to enforce using the singleton pattern.
	 * @param  \Title $title
	 */
	private function __construct( \Title $title, Config $config) {
		$this->config = $config;
		$this->nameSpace = $title->getNameSpace();
		$this->fetch();
	}

	//
	/**
	 * Fetches the page titles from the database.
	 */
	private function fetch() {

		( $this->config->preferShortTitles ) ? $sortOrder = 'ASC' : $sortOrder = 'DESC';
		// Build a blacklist of pages that are not supposed to be link
		// targets. This includes the current page.
		$blackList = str_replace( ' ', '_', '("' . implode( '","',$this->config->blackList ) . '")' );

		// Build our weight list. Make sure current namespace is first element
		$nameSpaces = array_diff( $this->config->nameSpaces, [ $this->nameSpace ] );
		array_unshift( $nameSpaces,  $this->nameSpace );

		// No need for sanitiy check. we are sure that we have at least one element in the array
		$weightSelect = "CASE page_namespace ";
		$currentWeight = 0;
		foreach ($nameSpaces as &$nameSpaceValue) {
				$currentWeight = $currentWeight + 100;
				$weightSelect = $weightSelect . " WHEN " . $nameSpaceValue . " THEN " . $currentWeight . PHP_EOL;
		}
		$weightSelect = $weightSelect . " END ";
		$nameSpacesClause = '(' . implode( ', ', $nameSpaces ) . ')';

		// Build an SQL query and fetch all page titles ordered by length from
		// shortest to longest. Only titles from 'normal' pages (namespace uid
		// = 0) are returned. Since the db may be sqlite, we need a try..catch
		// structure because sqlite does not support the CHAR_LENGTH function.
		$dbr = wfGetDB( DB_SLAVE );
		try {
			$this->queryResult = $dbr->select(
				'page',
				array( 'page_title', 'page_namespace' , "weight" => $weightSelect),
				array(
					'page_namespace IN ' . $nameSpacesClause,
					'CHAR_LENGTH(page_title) >= ' . $this->config->minimumTitleLength,
					'page_title NOT IN ' . $blackList,
				),
				__METHOD__,
				array( 'ORDER BY' => 'weight ASC, CHAR_LENGTH(page_title) ' . $sortOrder )
			);
		} catch (Exception $e) {
			$this->queryResult = $dbr->select(
				'page',
				array( 'page_title', 'page_namespace' , "weight" => $weightSelect ),
				array(
					'page_namespace IN ' . $nameSpacesClause,
					'LENGTH(page_title) >= ' . $this->config->minimumTitleLength,
					'page_title NOT IN ' . $blackList,
				),
				__METHOD__,
				array( 'ORDER BY' => 'weight ASC, LENGTH(page_title) ' . $sortOrder )
			);
		}
	}
}
