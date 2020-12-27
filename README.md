# AssetPacker für REDAXO

> zur Verwendung in einem [REDAXO](https://www.redoxo.org)-Addon
> getestet ab Version 5.10, lauffähig wahrscheinlich auch mit älteren REDAXO-5-Versionen

Das Tool erlaubt Cascading Stylesheets (CSS) oder Javascript (JS) aus verschiedenen Quellen zu einer
komprimierten Datei zusammenzufassen. Anwendungsbeispiele sind

- Konfigurationsänderungen im Backend mit Auswirkungen auf JS/CSS
- Bei der Installation CSS/JS basierend auf aktuellen Systemeinstellungen neu generieren
- Optimiertes Laden vom Webseiten durch wenige zusammengefasste und komprimierte JS/CSS statt vieler kleiner
- In einem Template kann zusätzlich ein passender HTML-Tag inkl. Buster erzeugt werden.

> - [Credits](#e)
> - [Das Konzept](#a)
> - [Installation](#f)
> - [Benutzung](#b)
>   - [Instanz eröffnen](#b1)
>   - [Neuanlage der Zieldatei erzwingen](#b2)
>   - [Dateien hinzufügen](#b3)
>   - [Platzhalter ersetzen](#b4)
>   - [Codeblöcke hinzufügen](#b5)
>   - [Zieldatei anlegen](#b6)
>   - [HTML-Tag erzeugen](#b7)
> - [Fehlerbehandlung](#c)
> - [Kompressor](#d)
>   - [CSS](#d1)
>   - [JS](#d2)
>   - [Andere Dateitypen](#d3)

<a name="e"></a>
## Credits

- Nutzt für die JS-Komprimierung [**JShrink**](https://github.com/tedivm/JShrink)
von [Robert Hafner](https://github.com/tedivm) (BSD 3-Clause License).

<a name="a"></a>
## Das Konzept

Der **AssetPacker** erzeugt eine minifizierte CSS- oder JS-Datei. Der Inhalt kann aus verschiedenen
Quellen stammen:

- in der REDAXO-Instanz zugängliche Dateien des jeweiligen Zieltyps
- Über eine URL remote abrufbare Dateien (z.B. aus Github-Repositories)
- direkter Quellcode

Die Zieldatei (Target) wird nach diesen Regeln angelegt:

- Die Zieldatei wird nur dann neu angelegt, wenn sie noch nicht existiert
- Ausnahme: Überschreiben ist ausdrücklich angefordert
- Die Quellen werden in der angegebenen Reihenfolge zur Zieldatei zusammengefasst.
- Der Suffix (z.B. .js oder .css) bestimmt, welche Quelldateien zulässig sind.
- Der Dateiname muss (sollte) als Pfadname angegeben werden.
- Da eine minifizierte Datei erzeugt wird, ist es good practice, auch `.min` im Namen zu verwenden.  

Die Quellen werden einzeln komprimiert mit folgender Regel

- Lokale Dateien mit Namen wie `name.min.js`, also `.min` direkt vor dem Suffix, werden nicht
  minifiziert.
- Per URL abgerufene Dateien werden nicht minifiziert, da hier i.d.R. minifizierte Dateien abrufbar
  sind.
- Beginnt die Datei mit einem Kommentar `/* ... */` ab dem ersten Byte, wird dieser Kommentar mit
  in die Zieldatei geschrieben. So können Copyright- und Lizenzangaben beibehalten werden.

Beispiele:

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->create();
```

```php
echo AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->create()
    ->getTag();
```
<a name="f"></a>
## Installation

Die Source-Datei [asset_packer.php](./asset_packer.php) wird im Lib-Verzeichnis eines REDAXO-Addons
gespeichert. Um Konflikten mit anderen Addons, die möglicherweise ebenfalls **AssetPacker** nutzen,
zu vermeiden reicht es aus, den Namespace in `asset_packer.php` zu ändern. Statt `AssetPacker`
kann z.B. der Name des Addons genutzt werden.

<a name="b"></a>
## Benutzung

Die einzelnen Methoden können als Pipe verbunden werden. Mit Ausnahme von `getTag()` liefern sie
die aktuelle Instanz als Rückgabewert.

<a name="b1"></a>
### Instanz eröffnen

Die Instanz wird mit einer statischen Methode angelegt. Sie analysiert den als Parameter angegebenen
Dateinamen auf formale Richtigkeit und legt eine Instanz passend zur Extension des Dateinamens an.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') );
```

Das Ergebnis ist eine Instanz der Klasse `AssetPacker_js`.

<a name="b2"></a>
### Neuanlage der Zieldatei erzwingen

Sofern die mit  `::target` festgelegte Zieldatei bereits existiert, wird diese **AssetPacker**-Instanz
keine neue Datei anlegen (`$overwrite = false;`). Damit wird beim Einsatz z.B. in einem Template
einmalig die Asset-Datei erzeugt. Dennoch kann es sinnvoll sein, in definierten Situationen die
Neuanlage zu erzwingen. Hierzu dient die Methode `overwrite( TRUE|false )`.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite();
```

Alternativ kann die Zieldatei gelöscht werden.

<a name="b3"></a>
### Dateien hinzufügen

Ganze Dateien können entweder aus lokal zugänglichen Quellen oder aus Remote-Quellen abgerufen
werden. Die Dateiangaben werden zunächst nur in eine interne Liste aufgenommen. Der Dateiname
muss formal korrekt sein und das gleiche Suffix wie die Zieldatei aufweisen. Die Existenz der Datei
wird hier nicht geprüft. Mit `http://` bzw. `https:://` beginnende Namen bezeichnen Remote-Ressourcen.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') );
```

<a name="b3"></a>
### Optionale Dateien hinzufügen

Analog zu `->addFile` können auch optionale Dateien z.B. hinzugefügt werden. Im Unterschied zu
`->addFile` wird keine Fehlermeldung ausgeworfen, wenn die Datei nicht gefunden wird.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->addOptionalFile( rex_path::addonData('myotheraddon','script.js') );
```

<a name="b4"></a>
### Platzhalter ersetzen

Insbesondere für Dateien besteht die Möglichkeit, Inhalte gegen andere Werte auszutauschen.
Z.B. werden Platzhalter in der Quelldatei durch aktualisierte Konfigurationsdaten überschrieben.
Die Anweisungen beziehen sich ausschließlich auf die letzte, zuvor mit `addFile()` bzw. `addCode()`
angegebene Ressource.

Die Platzhalter werden ersetzt bevor der Code minifiziert wird. Alle Vorkommen des Platzhalter werden
ersetzt.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->replace( '%xyz%', 123 )
    ->replace( 'let konfig_value_a = 99;', 'let konfig_value_a = 18;')
    ->addOptionalFile( rex_path::addonData('myotheraddon','script.js') );
```

Je nach Source-Code und Zielsetzung kann dasselbe Ergebnis durch Code-Blöcke erzielt werden, die
vorhergehende Elemente überschreiben.

<a name="b5"></a>
### Codeblöcke hinzufügen

Zudem können auch Codeblöcke als "Freitext" hinzugefügt werden. Darüber kann nicht per `addFile`
abrufbarer Code, kleine Code-Schnipsel oder programmatisch erzeugter Code eingefügt werden.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->replace ( '%xyz%', 123 )
    ->addcode( 'konfig_value_a = 18;')
    ->addOptionalFile( rex_path::addonData('myotheraddon','script.js') );
```

<a name="b6"></a>
### Zieldatei anlegen

Die vorhergehenden Methoden sammeln lediglich Quellen ein, ohne Dateien einzulesen oder
Verabeitungsschritte durchzuführen. Erst `create()` beginnt mit der Verarbeitung und versucht, die
Zieldatei anzulegen. `create()` bricht sofort ab, wenn `$overwrite == false` gilt.

Andernfalls werden die Dateien und Codeblöcke in der angegebenen Reihenfolge abgerufen und zur
(minifizierten) Zieldatei zusammengesetzt.

```php
AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->replace ( '%xyz%', 123 )
    ->addcode( 'konfig_value_a = 18;')
    ->addOptionalFile( rex_path::addonData('myotheraddon','script.js') )
    ->create();
```

<a name="b7"></a>
### HTML-Tag anlegen

Als zusätzliches Feature kann mit der Methode `getTag(«options»)` ein geeigneter HTML-Tag erzeugt werden.
Die Arbeitsweise der Methode orientiert sich an den Backend-Methoden `rex_view::addCssFile` und
`rex_view::addJsFile`. Die möglichen Optionen entsprechen denen der rex_view-Methoden.

Die Methode ist nur sinnvoll in Frontend-Templates.

```php
echo AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->overwrite()
    ->addFile( 'https://raw.githubusercontent.com/zenorocha/clipboard.js/master/dist/clipboard.min.js' )
    ->addFile( rex_path::addon('myaddon','install/prism.min.js') )
    ->replace ( '%xyz%', 123 )
    ->addcode( 'konfig_value_a = 18;')
    ->addOptionalFile( rex_path::addonData('myotheraddon','script.js') )
    ->create()
    ->getTag();
```

Oder als Frontend-Alternative zu den beiden rex_view-Methoden:
```php
echo AssetPacker\AssetPacker::target( rex_path::addonAssets('myaddon', 'script.min.js') )
    ->getTag();
```

Da `getTag` nicht die **AssetPacker**-Instanz zurückgibt, sondern den HTML-Code, muss dieser Aufruf am
Ende der Pipe stehen.

```html
<script type="text/javascript" src="../assets/addons/geolocation/test.min.js?buster=1605809629" ></script>
```

<a name="c"></a>
## Fehlerbehandlung

Es gibt diese Fehlertypen:

- **Fatale Fehler**
    - Als Target ist kein formal gültiger Dateiname angegeben.
    - Es gibt zum Typ der Target-Datei (Extension) keine Packer-Klasse.
    - Als Quelle ist kein formal gültiger Dateiname bzw. keine formal gültige URL angegeben.
    - Die Quelldatei bzw. URL hat eine andere Extension als durch den Target-Typ festgelegt.
- **Laufzeitfehler (create)**
    - URL kann nicht abgerufen werden
    - Datei nicht gefunden
    - Fehler beim Minifizieren (Exception des jeweiligen Minifizier-Tools)

Fatale Fehler führen zu einem Whoops. Der Entwickler muss sicherstellen, dass die angegebenen
Ressourcen-Namen korrekt sind.

Laufzeitfehler werden je nach Kontext unterschiedlich gehandhabt:

- Im Frontend-Kontext / Besucher-Ansicht wird ein Log-Eintrag geschrieben, der Create-Prozess
  abgebrochen und die Target-Datei nicht neu angelegt. So sollte zumindest mit der alten Target-Datei
  eine vernünftige Nutzung möglich sein.
- Im Backend-Kontext gibt es immer einen Whoops.
- Wenn ein Admin-User angemeldet ist, gibt es immer einen Whoops, auch im Frontend.

Fehler, die zu einem Whoops führen, werden als eigene Exceptions ausgeworfen.

- `AssetPacker_TargetError`
- `AssetPacker_SourceError`
- `AssetPacker_MinifyError`  
  Minifizierer-Exceptions werden in Exceptions vom Typ `AssetPacker_MinifyError` gekapselt.


<a name="d"></a>
## Kompressor

<a name="d1"></a>
### CSS

Für CSS-Dateien ist ein Tool in REDAXO enthalten, dass CSS-Dateien komprimiert:
[scssphp](https://scssphp.github.io/scssphp/) im System-Addon be_style. Darüber kann die
CSS-Komprimierung durchgeführt werden. Da scssphp ein [LESS/SASS-Compiler](https://sass-lang.com/)
ist, kann in den CSS-Dateien diese Syntax genutzt werden. Jedem Aufruf wird die Datei mit den
Variablen aus be_style (`be_style/plugins/redaxo/scss/_variables.scss`) vorangestellt, so dass auch
diese Werte z.B. für Farben und Abstände zur Verfügung stehen.

```php
class AssetPacker_css extends AssetPacker
{
    public function minify( string $content = '' ) : string
	{
        $scss_compiler = new ScssPhp\ScssPhp\Compiler();
        $scss_compiler->setNumberPrecision(10);
        $scss_compiler->setFormatter(ScssPhp\ScssPhp\Formatter\Compressed::class);
        $styles = '@import \''.\rex_path::addon('be_style','plugins/redaxo/scss/_variables').'\';';
        return $scss_compiler->compile($styles.$content);
    }
    ...
}
```

<a name="d2"></a>
### JS

Für JS-Komprimierung bietet der REDAXO-Core keine eingebaute Funktion. Hier wird auf das Tool
[**JShrink**](https://github.com/tedivm/JShrink) zurückgegriffen. Code, Lizenz und Anmerkungen
sind Teil von **asset_packer.php**. JShrink entfernt überflüssige Leerzeichen und die Kommentare.

```php
class AssetPacker_js extends AssetPacker
{
    public function minify( string $content = '' ) : string
	{
        return Minifier::minify($content);
	}
    ...
}
```

<a name="d3"></a>
### Andere Dateitypen

Falls auch andere Dateitypen (z.B. "dateiname.**xxx**") komprimierbar sein sollen, muss dafür ein
eigener Kompresser als `AssetPacker_xxx` angelegt werden. `AssetPacker_js` bzw.
`AssetPacker_css` können als Beispiel dienen.

Nur die Methoden `minify` und `getTag` müssen überschrieben werden.
