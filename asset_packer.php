<?php
/**
 *  AssetPacker - Support für REDAXO-Addons
 *
 *  @author      Christoph Böcker <https://github.com/christophboecker/>
 *  @version     1.0
 *  @copyright   Christoph Böcker
 *  @license     Die AssetPacker-Klassen: MIT-License <https://opensource.org/licenses/MIT>
 *               Die JS-Minifier-Klasse: BSD 3-Clause License <https://github.com/tedivm/JShrink/blob/master/LICENSE>
 *  @source      Die Dateien liegen auf Github: <https://github.com/christophboecker/AssetPacker/>
 *  @see         Handbuch auf Github: <https://github.com/christophboecker/AssetPacker/blob/master/readme.md>
 *
 *  JShrink: https://github.com/tedivm/JShrink
 *  Adaptierter Code, Copyright usw. im hinteren Teil dieser Datei (suche: class Minifier)
 *
 *  Die Klasse "AssetPacker" stellt Methoden zum erzeugen komprimierter (.min.) Assets wie CSS- und
 *  JS-Dateien aus mehreren Einzelkomponenten (via URL, lokale Dateien, Codeblöcke) zur Verfügung
 *
 *  Wie man AssetPacker benutzt steht im Handbuch auf Github (link siehe oben @see)
 *
 *  ------------------------------------------------------------------------------------------------
 */

namespace AssetPacker;
/**
 *  @package AssetPacker
 *  @method AssetPacker     target( string $targetPath )
 *  @method AssetPacker     overwrite( bool $overwrite = true )
 *  @method AssetPacker     addFile( string $assetPath )
 *  @method AssetPacker     addCode( string $code )
 *  @method AssetPacker     replace( string $marker, string $replacement='' )
 *  @method AssetPacker     create()
 *  @method string          getTag ( )
 *  @method string          minify ( string $content )
 *  @method string          _minify( string $content, string $name = '' )
 *  @method string|false    _getComment( string &$content )
 *  @method string|false    fileinfo( $filename )
 */

abstract class AssetPacker
{
    // Um einen einleitenden Kommentar zu identifizieren
    // in AssetPacker_xyz überschreiben falls andere Kommentarzeichen benutzt werden.
    public $remOn = '/*';
    public $remOff = '*/';

    // Fehlermeldungen
    const ERR_NO_TARGET = 'AssetPacker: missing valid target-filename! Found "%s"';
    const ERR_TARGET_EXT_INVALID = 'AssetPacker: target-type "%s" is not supported!';
    const ERR_NO_FILE = 'AssetPacker: missing or invalid ressource-name "%s"! Please check the manual.';
    const ERR_FILE_TYPE = 'AssetPacker:ressource "%s" not of type "%s"!';
    const ERR_FILE_NOT_FOUND = 'AssetPacker: "%s" not found! Minificaton stopped.';
    const ERR_MINIFY = 'AssetPacker: "%s" not minimized [%s]! Minificaton stopped.';

    // Source-Typen
    const CODE = 1;                 // Direkt angegebener Code
    const HTTP = 2;                 // URL zu einer abrufbaren Datei; wird nicht minifiziert
    const FILE = 3;                 // Pfad zu einer lokalen, nicht minifizierten Datei
    const COMPRESSED = 4;           // Pfad zu einer lokalen, minifizierten Datei

    // interne Variablen
    protected $content = [];        // Liste der Quellen
	protected $overwrite = false;   // Zieldatei überschreiben?
    protected $timestamp = false;   // false = keine Zieldatei, sonst deren Timestamp
    protected $type = '';           // Bearbeiteter Dateityp (.css, .js)
    protected $target = false;      // Pfadname der Zieldatei
    protected $current = null;      // Pointer auf das letzte zugefühte Element in $content

	public function __construct( array $fileinfo )
	{
        if( $fileinfo ) {
            $this->target = implode('',$fileinfo);
            $this->timestamp = @filemtime( $this->target );
            $this->overwrite = false === $this->timestamp;
            $this->type = $fileinfo['ext'];
        }
	}

    /**
     *   Getter um die Instanz entsprechend des Dateityps anzulegen.
     *   Öffnet die Klasse AssetPacker_«ext» wenn vorhanden
     *
     *   @param     string              Pfadname der Zieldatei
     *   @return    AssetPacker|null    die Asset-Packer-Instanz oder NULL
     *
     *   @throws    AssetPacker_TargetError
     *                                  Abbruch wenn keine oder ein fehlerhafter Zielname angegeben wurde
     *                                  Abbruch wenn der Dateityp nicht unterstützt wird
     */
    public static function target( string $targetPath ) : AssetPacker
    {
        $fileinfo = self::fileinfo( $targetPath );
        if( !$fileinfo || $fileinfo['http'] ) {
            throw new AssetPacker_TargetError(sprintf(self::ERR_NO_TARGET,$targetPath),1);
        }
        $class = self::class . '_' . strtolower(substr($fileinfo['ext'],1));
        if( !is_subclass_of($class,self::class) ) {
            throw new AssetPacker_TargetError(sprintf(self::ERR_TARGET_EXT_INVALID,$fileinfo['ext']),1);
        }
        return new $class( $fileinfo );
    }

    /**  Aktiviert den Overwrite-Modus.
     *   Damit kann erzwungen werden, dass eine schon existierende Target-Datei neu angelegt wird.
     *
     *   @var     bool          TRUE|false legt fest, ob eine gecachte .min-Version überschreiben werden soll
     *   @return  AssetPacker   die Asset-Packer-Instanz oder NULL
     */
    public function overwrite( bool $overwrite = true ) : AssetPacker
    {
        if( $this->timestamp ) {
            $this->overwrite = $overwrite;
        }
        return $this;
    }

    /**  Fügt der Quellenliste eine Datei hinzu
     *   Sofern es sich um eine URL handelt, wird die URL ohne weitere Prüfung akzeptiert.
     *   Dateien mit .min. im Namen werden nicht komprimiert.
     *   Fügt das Element der Content-Liste hinzu; Verarbeitung erst mit create()
     *   setzt einen Pointer (current) auf das zuletzt hinzugefügte Element
     *
     *   @var     string        Pfad der AssetDatei bzw. URL zum Abruf
     *   @var     bool          FALSE|true legt fest, ob eine gecachte .min-Version überschreiben werden soll
     *   @return  AssetPacker   die Asset-Packer-Instanz oder NULL
     *
     *   @throws  AssetPacker_SourceError
     *                          Abbruch wenn keine oder ein fehlerhafter Dateiname angegeben wurde
     *                          Abbruch wenn der Dateityp nicht der Zieldatei entspricht
     */
    public function addFile( string $assetPath ) : AssetPacker
	{
        // Pfadname muss formal richtig sein
        $fileinfo = self::fileinfo( $assetPath );
        if( !$fileinfo ) {
            throw new AssetPacker_SourceError(sprintf(self::ERR_NO_FILE,$assetPath),1);
        }

        // Suffix muss dem des aktuellen Typs entsprechen
        if( strcasecmp($this->type,$fileinfo['ext']) ) {
            throw new AssetPacker_SourceError(sprintf(self::ERR_FILE_TYPE,$assetPath,$this->type),1);
        }

        // Den Eintrag in die Liste übernehmen und beenden
        $this->content[] = [
            'type' => ($fileinfo['http'] ? self::HTTP : ( $fileinfo['min'] ? self::COMPRESSED : self::FILE ) ),
            'source' => $fileinfo,
            'name' => $assetPath,
            'replace' => [],
            'content' => '',
        ];
        $this->current = &$this->content[array_key_last($this->content)];
		return $this;
	}

    /**  Fügt der Quellenliste einen Codeblock hinzu
     *   Codeblöcke werden immer neu comprimiert.
     *   Fügt das Element der Content-Liste hinzu; Verarbeitung erst mit create()
     *   setzt einen Pointer (current) auf das zuletzt hinzugefügte Element
     *
     *   @var     string        Codeblock
     *   @return  AssetPacker   die Asset-Packer-Instanz oder NULL
     */
    public function addCode( string $code ) : AssetPacker
	{
        $this->content[] = [
            'type' => self::CODE,
            'source' => null,
            'name' => 'code-block',
            'replace' => [],
            'content' => trim($code),
        ];
        $this->current = &$this->content[array_key_last($this->content)];
        return $this;
	}

    /**  Fügt dem aktuell letzten Element Ersetzungen hinzu
     *   z.B. um in einer Datei Platzhalter gegen aktuelle Werte zu tauschen
     *   Die Werte werden getauscht BEVOR die Datei minifiziert wird.
     *   Gedacht um Parameter und Vorgabewerte einzutragen, nicht für größerer Textblöcke.
     *   auch nicht für self::HTTP gedacht
     *
     *   @var     string        Needle
     *   @var     string        Statt Needle einzusetzender Text
     *   @return  AssetPacker   die Asset-Packer-Instanz oder NULL
     */
    public function replace( string $marker, string $replacement='' ) : AssetPacker
    {
        if( $marker && null !== $this->current && self::HTTP != $this->current['type'] ) {
            $this->current['replace']['marker'][] = $marker;
            $this->current['replace']['replacement'][] = $replacement;
        }
        return $this;
    }

    /**  legt die Zieldatei aus den angegebenen Elementen an
     *   keine Elemente -> leere Zieldatei
     *   Der Vorgang startet nur, wenn "overwrite" angefordert wurde
     *   (Zieldatei nicht vorhanden oder overwrite() )
     *   Fehler z.B. vom jeweiligen Minifizierer werden intern abgefangen und ausgewertet
     *   Kritische Fehler (Konstruktionsfehler) bzw. Fehler im Backend bei einem Admin-User
     *   zu einem Whoops. Alles andere wird still beendet mit einem Log-Eintrag.
     *
     *   @return  AssetPacker   die Asset-Packer-Instanz oder NULL
     *
     *   @throws  AssetPacker_MinifyError
     *   @throws  AssetPacker_SourceError
     */
    public function create() : AssetPacker
	{
        // keine Zieldatei angegeben; Abbruch
        if( !$this->overwrite ) return $this;

        try {
            $bundle = [];
            foreach( $this->content as $item ){

                $filename = $item['name'];

                // Außer wenn Code: Daten abrufen (HTTP) oder einlesen (File)
                if( self::CODE !== $item['type'] ){
                    $item['content'] = \rex_file::get( $filename );
                    // Content kann nicht abgerufen werden; Meldung und Abbruch
                    if( null === $item['content'] ) {
                        throw new AssetPacker_SourceError(sprintf(self::ERR_FILE_NOT_FOUND,$filename), 2);
                    }
                }

                // Variablen ersetzen
                if( $item['replace']['marker'] ) {
                    $item['content'] = str_replace( $item['replace']['marker'], $item['replace']['replacement'], $item['content'] );
                }

                // Außer wenn schon comprimiert: Code comprimieren
                if( self::COMPRESSED !== $item['type'] ){
                    $item['content'] = $this->_minify( $item['content'], $item['name'] );
                }

                // Dem Paket hinzufügen
                if( $item['content'] ) {
                    $bundle[] = $item['content'];
                }

            }

            \rex_file::put( $this->target, implode(" \n\r",$bundle) );
            $this->timestamp = @filemtime( $this->target );

        } catch (\Exception $e) {

            // Fehlermeldung je nach Context "still" oder als "Whoops"
            if( 1 == $e->getCode() || \rex::isBackend() || \rex::getUser()->isAdmin() ){
                throw $e;
            } else {
                \rex_logger::logError(E_ERROR, $e->getMessage(), $e->getFile(), $e->getLine());
            }
        }

		return $this;
	}

    /**  Minifiziert den Code
     *   Ein einleitender Kommentar z.B. mit Versions- und Copyright-Informationen bleibt enthalten
     *   und wird nach dem Minifizieren dem minifizierten Code vorangestellt.
     *
     *   Fehler beim Minifizieren führen in minity() zu einer Exception. Die wird in create()
     *   abgefangen, nicht hier.
     *
     *   @var     string        Der Quellcode
     *   @var     string        Der DAteiname für ggf.vom Minifizierer erzeugte Fehlermeldungen
     *   @return  string        Der minifizierte Code.
     *
     *   @throws  AssetPacker_MinifyError
     *                          Nimmt eine Fehlermeldung vom Minifier auf und leitet sie mit
     *                          erweitertem Text weiter
     */
    private function _minify( string $content, string $name = '' ) : string
    {
        // einleitender Kommentar /*...*/ bleibt erhalten
        $rem = $this->_getComment( $content );

        // Übrigen Text minifizieren
        try {
            $content = $this->minify( $content );
        } catch (\Exception $e) {
            throw new AssetPacker_MinifyError(sprintf(self::ERR_MINIFY,$name,$e->getMessage()), 2);
        }

        // ggf. einleitenden Kommentar wieder einfügen
        return $rem ? $rem . PHP_EOL . $content : $content;
	}

    /**  Ermittelt den einleitenden Kommentar im Quellcode
     *   Der Kommentar darf z.B. Versions- und Copyright-Informationen enthalten, die
     *   später wieder vor der minifizierten Datei eingefügt werden.
     *
     *   @var     string        Der Quellcode als Referenz
     *   @return  string|false  Entweder der Kommentar oder false für "kein Kommentar"
     */
    public function _getComment( string &$content )
    {
        $pattern = '/^'.preg_quote($this->remOn,'/').'.*?'.preg_quote($this->remOff,'/').'/s';
        if( preg_match( $pattern, $content, $matches ) && $matches ){
            return substr( $content, 0, strlen($matches[0]));
        }
        return false;
    }

    /**  Zerlegt einen Pfadnamen ähnlich wie PHPs pathinfo in seine Bestandteile und wirft auch aus,
     *   ob es eine minifizierte Datei ist (.min als vorletztes Element im Dateinamen)
     *   Beispiel: /abc/def/ghi/lorem.min.js    abc/def/ghi/lorem.max.js    lorem.min.js    lorem.min
     *       http:
     *       path: /abc/def/ghi/                abc/def/ghi/
     *       name: lorem                        lorem.max                   lorem           lorem
     *        min: .min                         «leer»                      .min
     *        ext: .js                          .js                         .js             .min
     *   Rückgabe ist entweder ein Array mit den vier Bestandteilen oder false für
     *   formal falsch (ohne Extension) bzw. leer
     *   Auch möglich: URL, die mit http(s):// beginnt.
     *
     *   @param  string         Der zu untersuchenede Pfad
     *   @return array|false    [http=>,path=>,name=>,min=>,ext=>] oder false
     */
    static function fileinfo( $filename )
    {
        $pattern = '/^((?<http>https?\:\/\/)|(?<ws>.*?\:\/\/))?(?<path>(.*?\/)*)(?<name>.*?)(?<min>\.min)?(?<ext>\.\w+)$/';
        if( preg_match( $pattern, trim($filename), $pathinfo ) && !$pathinfo['ws'] ) {
            unset( $pathinfo['ws'] );
            return array_filter($pathinfo,'is_string',ARRAY_FILTER_USE_KEY);
        }
        return false;
    }

    /**  Führt die dateityp-spezifische Minimierung eines Codeblocks durch
     *
     *   Wenn die Minifizierung misslingt muss mit einer Exceptionabgebrochen werden.
     *   Die Exception bearbeitet die aufrufende Methode (hier: _minifty())
     *
     *   @param  string     der umkomprimierte Codeblock
     *   @return string     der komprimierte Codeblock
     */
    abstract public function minify ( string $content ) : string;

    /**  Erzeugt für die Target-Datei den passenden HTML-Tag zum Einbinden der Ressource
     *
     *   @return string     der HTML-Tag
     */
    abstract public function getTag ( ) : string;

} // end of class AssetPacker

class AssetPacker_TargetError extends \Exception {}
class AssetPacker_SourceError extends \Exception {}
class AssetPacker_MinifyError extends \Exception {}


/**  Variante für CSS-Dateien
 *
 *   Der Minifier für CSS nutzt das im READXO enthaltene ScssPhp als Minifizierer.
 *   Als zusätzicher Benefit die CSS-Dateien LESS/SASS-Format haben
 *   Die Redaxo-LESS/SASS-Variablen aus be_style/plugins/redaxo/scss/_variables werden stets
 *   automatisch bereitgestellt.
 *
 *   @package AssetPacker
 */

class AssetPacker_css extends AssetPacker
{

    public function minify( string $content ) : string
    {
        $scss_compiler = new ScssPhp\ScssPhp\Compiler();
        $scss_compiler->setNumberPrecision(10);
        $scss_compiler->setFormatter(ScssPhp\ScssPhp\Formatter\Compressed::class);
        $styles = '@import \''.\rex_path::addon('be_style','plugins/redaxo/scss/_variables').'\';';
        return $scss_compiler->compile($styles.$content);
	}

    public function getTag( string $media = 'all' ) : string
    {
        // Pathname relativ zu rex_path
        $asset = \rex_url::base( \rex_path::relative( $this->target ) );

        if (!\rex::isDebugMode() && $this->timestamp)
        {
            $asset = \rex_url::backendController(['asset' => $asset, 'buster' => $this->timestamp]);
        }
        elseif ($this->timestamp )
        {
            $asset .= '?buster=' . $this->timestamp;
        }
        return '    <link rel="stylesheet" type="text/css" media="' . $media . '" href="' . $asset .'" />';
    }

} // end of class AssetPacker_css

/**  Variante für JS-Dateien
 *
 *   Komprimiert den JS-Code recht einfach durch Entfernen der Kommentare und unnötiger Leerzeichen.
 *
 *   @package AssetPacker
 */

class AssetPacker_js extends AssetPacker
{

    public function minify( string $content = '' ) : string
	{
        return Minifier::minify($content);
	}

    public function getTag( array $options = [] ) : string
	{
        // Pathname relativ zu rex_path
        $asset = \rex_url::base( \rex_path::relative( $this->target ) );

        if (array_key_exists(\rex_view::JS_IMMUTABLE, $options) && $options[\rex_view::JS_IMMUTABLE])
        {
		    if (!\rex::isDebugMode() && $this->timestamp)
		    {
			    $asset = \rex_url::backendController(['asset' => $asset, 'buster' => $this->timestamp]);
		    }
        }
		elseif ( $this->timestamp )
		{
			$asset .= '?buster=' . $this->timestamp;
		}

        $attributes = [];
        if (array_key_exists(\rex_view::JS_ASYNC, $options) && $options[\rex_view::JS_ASYNC]) {
            $attributes[] = 'async="async"';
        }
        if (array_key_exists(\rex_view::JS_DEFERED, $options) && $options[\rex_view::JS_DEFERED]) {
            $attributes[] = 'defer="defer"';
        }

        return "\n" . '    <script type="text/javascript" src="' . $asset .'" '. implode(' ', $attributes) .'></script>';
    }

} // end of class AssetPacker_js

/*
 * The following code is from the JShrink package. (https://github.com/tedivm/JShrink)
 *
 *  > Erweiterung von Christiph Böcker für nettere Fehlermeldungen
 *  >   function cb_line_and_col()          Rechnet eine Char-Position wieder in Zeile/Spalte um
 *  >   In den Exceptions statt Position den Wert aus cb_line_and_col() einsetzen
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 *  BSD 3-Clause License
 *
 *  Copyright (c) 2009, Robert Hafner
 *  All rights reserved.
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 *
 *  1. Redistributions of source code must retain the above copyright notice, this
 *     list of conditions and the following disclaimer.
 *
 *  2. Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *
 *  3. Neither the name of the copyright holder nor the names of its
 *     contributors may be used to endorse or promote products derived from
 *     this software without specific prior written permission.
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 *  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 *  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 *  DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 *  FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 *  DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 *  SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 *  CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 *  OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * JShrink
 *
 *
 * @package    JShrink
 * @author     Robert Hafner <tedivm@tedivm.com>
 */

/**
 * Minifier
 *
 * Usage - Minifier::minify($js);
 * Usage - Minifier::minify($js, $options);
 * Usage - Minifier::minify($js, array('flaggedComments' => false));
 *
 * @package JShrink
 * @author Robert Hafner <tedivm@tedivm.com>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD License
 */
class Minifier
{
    /**
     * The input javascript to be minified.
     *
     * @var string
     */
    protected $input;

    /**
     * The location of the character (in the input string) that is next to be
     * processed.
     *
     * @var int
     */
    protected $index = 0;

    /**
     * The first of the characters currently being looked at.
     *
     * @var string
     */
    protected $a = '';

    /**
     * The next character being looked at (after a);
     *
     * @var string
     */
    protected $b = '';

    /**
     * This character is only active when certain look ahead actions take place.
     *
     *  @var string
     */
    protected $c;

    /**
     * Contains the options for the current minification process.
     *
     * @var array
     */
    protected $options;

    /**
     * Contains the default options for minification. This array is merged with
     * the one passed in by the user to create the request specific set of
     * options (stored in the $options attribute).
     *
     * @var array
     */
    protected static $defaultOptions = array('flaggedComments' => true);

    /**
     * Contains lock ids which are used to replace certain code patterns and
     * prevent them from being minified
     *
     * @var array
     */
    protected $locks = array();

    /**
     * Takes a string containing javascript and removes unneeded characters in
     * order to shrink the code without altering it's functionality.
     *
     * @param  string      $js      The raw javascript to be minified
     * @param  array       $options Various runtime options in an associative array
     * @throws \Exception
     * @return bool|string
     */
    public static function minify($js, $options = array())
    {
        try {
            ob_start();

            $jshrink = new Minifier();
            $js = $jshrink->lock($js);
            $jshrink->minifyDirectToOutput($js, $options);

            // Sometimes there's a leading new line, so we trim that out here.
            $js = ltrim(ob_get_clean());
            $js = $jshrink->unlock($js);
            unset($jshrink);

            return $js;

        } catch (\Exception $e) {

            if (isset($jshrink)) {
                // Since the breakdownScript function probably wasn't finished
                // we clean it out before discarding it.
                $jshrink->clean();
                unset($jshrink);
            }

            // without this call things get weird, with partially outputted js.
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Processes a javascript string and outputs only the required characters,
     * stripping out all unneeded characters.
     *
     * @param string $js      The raw javascript to be minified
     * @param array  $options Various runtime options in an associative array
     */
    protected function minifyDirectToOutput($js, $options)
    {
        $this->initialize($js, $options);
        $this->loop();
        $this->clean();
    }

    /**
     *  Initializes internal variables, normalizes new lines,
     *
     * @param string $js      The raw javascript to be minified
     * @param array  $options Various runtime options in an associative array
     */
    protected function initialize($js, $options)
    {
        $this->options = array_merge(static::$defaultOptions, $options);
        $js = str_replace("\r\n", "\n", $js);
        $js = str_replace('/**/', '', $js);
        $this->input = str_replace("\r", "\n", $js);

        // We add a newline to the end of the script to make it easier to deal
        // with comments at the bottom of the script- this prevents the unclosed
        // comment error that can otherwise occur.
        $this->input .= PHP_EOL;

        // Populate "a" with a new line, "b" with the first character, before
        // entering the loop
        $this->a = "\n";
        $this->b = $this->getReal();
    }

    /**
     * The primary action occurs here. This function loops through the input string,
     * outputting anything that's relevant and discarding anything that is not.
     */
    protected function loop()
    {
        while ($this->a !== false && !is_null($this->a) && $this->a !== '') {

            switch ($this->a) {
                // new lines
                case "\n":
                    // if the next line is something that can't stand alone preserve the newline
                    if (strpos('(-+{[@', $this->b) !== false) {
                        echo $this->a;
                        $this->saveString();
                        break;
                    }

                    // if B is a space we skip the rest of the switch block and go down to the
                    // string/regex check below, resetting $this->b with getReal
                    if($this->b === ' ')
                        break;

                // otherwise we treat the newline like a space

                case ' ':
                    if(static::isAlphaNumeric($this->b))
                        echo $this->a;

                    $this->saveString();
                    break;

                default:
                    switch ($this->b) {
                        case "\n":
                            if (strpos('}])+-"\'', $this->a) !== false) {
                                echo $this->a;
                                $this->saveString();
                                break;
                            } else {
                                if (static::isAlphaNumeric($this->a)) {
                                    echo $this->a;
                                    $this->saveString();
                                }
                            }
                            break;

                        case ' ':
                            if(!static::isAlphaNumeric($this->a))
                                break;

                        default:
                            // check for some regex that breaks stuff
                            if ($this->a === '/' && ($this->b === '\'' || $this->b === '"')) {
                                $this->saveRegex();
                                continue;
                            }

                            echo $this->a;
                            $this->saveString();
                            break;
                    }
            }

            // do reg check of doom
            $this->b = $this->getReal();

            if(($this->b == '/' && strpos('(,=:[!&|?', $this->a) !== false))
                $this->saveRegex();
        }
    }

    /**
     * Resets attributes that do not need to be stored between requests so that
     * the next request is ready to go. Another reason for this is to make sure
     * the variables are cleared and are not taking up memory.
     */
    protected function clean()
    {
        unset($this->input);
        $this->index = 0;
        $this->a = $this->b = '';
        unset($this->c);
        unset($this->options);
    }

    /**
     * Returns the next string for processing based off of the current index.
     *
     * @return string
     */
    protected function getChar()
    {
        // Check to see if we had anything in the look ahead buffer and use that.
        if (isset($this->c)) {
            $char = $this->c;
            unset($this->c);

        // Otherwise we start pulling from the input.
        } else {
            $char = substr($this->input, $this->index, 1);

            // If the next character doesn't exist return false.
            if (isset($char) && $char === false) {
                return false;
            }

            // Otherwise increment the pointer and use this char.
            $this->index++;
        }

        // Normalize all whitespace except for the newline character into a
        // standard space.
        if($char !== "\n" && ord($char) < 32)

            return ' ';

        return $char;
    }

    /**
     * This function gets the next "real" character. It is essentially a wrapper
     * around the getChar function that skips comments. This has significant
     * performance benefits as the skipping is done using native functions (ie,
     * c code) rather than in script php.
     *
     *
     * @return string            Next 'real' character to be processed.
     * @throws \RuntimeException
     */
    protected function getReal()
    {
        $startIndex = $this->index;
        $char = $this->getChar();

        // Check to see if we're potentially in a comment
        if ($char !== '/') {
            return $char;
        }

        $this->c = $this->getChar();

        if ($this->c === '/') {
            return $this->processOneLineComments($startIndex);

        } elseif ($this->c === '*') {
            return $this->processMultiLineComments($startIndex);
        }

        return $char;
    }

    /**
     * Removed one line comments, with the exception of some very specific types of
     * conditional comments.
     *
     * @param  int    $startIndex The index point where "getReal" function started
     * @return string
     */
    protected function processOneLineComments($startIndex)
    {
        $thirdCommentString = substr($this->input, $this->index, 1);

        // kill rest of line
        $this->getNext("\n");

        if ($thirdCommentString == '@') {
            $endPoint = $this->index - $startIndex;
            unset($this->c);
            $char = "\n" . substr($this->input, $startIndex, $endPoint);
        } else {
            // first one is contents of $this->c
            $this->getChar();
            $char = $this->getChar();
        }

        return $char;
    }

    /**
     * Skips multiline comments where appropriate, and includes them where needed.
     * Conditional comments and "license" style blocks are preserved.
     *
     * @param  int               $startIndex The index point where "getReal" function started
     * @return bool|string       False if there's no character
     * @throws \RuntimeException Unclosed comments will throw an error
     */
    protected function processMultiLineComments($startIndex)
    {
        $this->getChar(); // current C
        $thirdCommentString = $this->getChar();

        // kill everything up to the next */ if it's there
        if ($this->getNext('*/')) {

            $this->getChar(); // get *
            $this->getChar(); // get /
            $char = $this->getChar(); // get next real character

            // Now we reinsert conditional comments and YUI-style licensing comments
            if (($this->options['flaggedComments'] && $thirdCommentString === '!')
                || ($thirdCommentString === '@') ) {

                // If conditional comments or flagged comments are not the first thing in the script
                // we need to echo a and fill it with a space before moving on.
                if ($startIndex > 0) {
                    echo $this->a;
                    $this->a = " ";

                    // If the comment started on a new line we let it stay on the new line
                    if ($this->input[($startIndex - 1)] === "\n") {
                        echo "\n";
                    }
                }

                $endPoint = ($this->index - 1) - $startIndex;
                echo substr($this->input, $startIndex, $endPoint);

                return $char;
            }

        } else {
            $char = false;
        }

        if($char === false)
            throw new \RuntimeException('Unclosed multiline comment ' . $this->cb_line_and_col($this->index - 2));

        // if we're here c is part of the comment and therefore tossed
        if(isset($this->c))
            unset($this->c);

        return $char;
    }

    /**
     * Pushes the index ahead to the next instance of the supplied string. If it
     * is found the first character of the string is returned and the index is set
     * to it's position.
     *
     * @param  string       $string
     * @return string|false Returns the first character of the string or false.
     */
    protected function getNext($string)
    {
        // Find the next occurrence of "string" after the current position.
        $pos = strpos($this->input, $string, $this->index);

        // If it's not there return false.
        if($pos === false)

            return false;

        // Adjust position of index to jump ahead to the asked for string
        $this->index = $pos;

        // Return the first character of that string.
        return substr($this->input, $this->index, 1);
    }

    /**
     * When a javascript string is detected this function crawls for the end of
     * it and saves the whole string.
     *
     * @throws \RuntimeException Unclosed strings will throw an error
     */
    protected function saveString()
    {
        $startpos = $this->index;

        // saveString is always called after a gets cleared, so we push b into
        // that spot.
        $this->a = $this->b;

        // If this isn't a string we don't need to do anything.
        if ($this->a !== "'" && $this->a !== '"') {
            return;
        }

        // String type is the quote used, " or '
        $stringType = $this->a;

        // Echo out that starting quote
        echo $this->a;

        // Loop until the string is done
        while (true) {

            // Grab the very next character and load it into a
            $this->a = $this->getChar();

            switch ($this->a) {

                // If the string opener (single or double quote) is used
                // output it and break out of the while loop-
                // The string is finished!
                case $stringType:
                    break 2;

                // New lines in strings without line delimiters are bad- actual
                // new lines will be represented by the string \n and not the actual
                // character, so those will be treated just fine using the switch
                // block below.
                case "\n":
                    throw new \RuntimeException('Unclosed string ' . $this->cb_line_and_col($startpos) );
                    break;

                // Escaped characters get picked up here. If it's an escaped new line it's not really needed
                case '\\':

                    // a is a slash. We want to keep it, and the next character,
                    // unless it's a new line. New lines as actual strings will be
                    // preserved, but escaped new lines should be reduced.
                    $this->b = $this->getChar();

                    // If b is a new line we discard a and b and restart the loop.
                    if ($this->b === "\n") {
                        break;
                    }

                    // echo out the escaped character and restart the loop.
                    echo $this->a . $this->b;
                    break;


                // Since we're not dealing with any special cases we simply
                // output the character and continue our loop.
                default:
                    echo $this->a;
            }
        }
    }

    /**
     * When a regular expression is detected this function crawls for the end of
     * it and saves the whole regex.
     *
     * @throws \RuntimeException Unclosed regex will throw an error
     */
    protected function saveRegex()
    {
        echo $this->a . $this->b;

        while (($this->a = $this->getChar()) !== false) {
            if($this->a === '/')
                break;

            if ($this->a === '\\') {
                echo $this->a;
                $this->a = $this->getChar();
            }

            if($this->a === "\n")
                throw new \RuntimeException('Unclosed regex pattern ' . $this->cb_line_and_col($this->index) );

            echo $this->a;
        }
        $this->b = $this->getReal();
    }

    /**
     * Checks to see if a character is alphanumeric.
     *
     * @param  string $char Just one character
     * @return bool
     */
    protected static function isAlphaNumeric($char)
    {
        return preg_match('/^[\w\$\pL]$/', $char) === 1 || $char == '/';
    }

    /**
     * Replace patterns in the given string and store the replacement
     *
     * @param  string $js The string to lock
     * @return bool
     */
    protected function lock($js)
    {
        /* lock things like <code>"asd" + ++x;</code> */
        $lock = '"LOCK---' . crc32(time()) . '"';

        $matches = array();
        preg_match('/([+-])(\s+)([+-])/S', $js, $matches);
        if (empty($matches)) {
            return $js;
        }

        $this->locks[$lock] = $matches[2];

        $js = preg_replace('/([+-])\s+([+-])/S', "$1{$lock}$2", $js);
        /* -- */

        return $js;
    }

    /**
     * Replace "locks" with the original characters
     *
     * @param  string $js The string to unlock
     * @return bool
     */
    protected function unlock($js)
    {
        if (empty($this->locks)) {
            return $js;
        }

        foreach ($this->locks as $lock => $replacement) {
            $js = str_replace($lock, $replacement, $js);
        }

        return $js;
    }

    /** CB:
     * Baut aus einem Pointer auf den Code ($this-input) einen lesser lesbaren und für die
     * Fehlersuche besser handhabbaren Werte Zeile/Spalte.
     *
     * @param  integer  Pointer auf $input
     * @return string
     */
    function cb_line_and_col( $at ){
        $line = substr_count($this->input,"\n",0,$at) +  1;
        $col = $at - strrpos( substr($this->input,0,$at),"\n" ) - 1;
        return "at Line $line Col $col";
    }

} // end of class Minifier
