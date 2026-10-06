# schema.org validator

A PHP library that validates schema.org JSON-LD. It checks a graph against the full schema.org vocabulary, which is generated from the official dump, and against optional profiles of extra rules such as Google rich result requirements. It has no runtime dependencies and does not need WordPress.

Tools that build JSON-LD, such as spatie/schema-org, do not check it. This library only validates.

## Install

```bash
composer require humanmade/schema-org-validator
```

It needs PHP 8.0 or later.

## Usage

```php
use HumanMade\SchemaOrgValidator\Profiles;
use HumanMade\SchemaOrgValidator\Validator;

$validator = new Validator(null, ...Profiles::google());
$report = $validator->validate($json); // a JSON string or a decoded array

if (!$report->isValid()) {
    foreach ($report->errors() as $issue) {
        printf("%s %s: %s\n", $issue->code(), $issue->path(), $issue->message());
    }
}

$report->warnings();
$report->notices();
$report->toArray();
```

The input can be a single node, a list of nodes, or an object with `@context` and `@graph`. Types can be written as `Article`, `schema:Article`, `https://schema.org/Article` or `http://schema.org/Article`, and a node can have several types. Nested nodes are checked too. Terms from other vocabularies are ignored.

A node with an `@id` and other keys is a definition. An object with only an `@id` is a reference, and is checked against the node it points to when that node is in the graph.

`new Validator()` uses the bundled vocabulary. Pass your own `Vocabulary` as the first argument to use other data. Any further arguments are profiles.

`Report::isValid()` is true when there are no errors. Warnings and notices do not make a report invalid.

Each `Issue` has a `severity()` (`error`, `warning` or `notice`), a `code()`, a short English `message()`, a JSON pointer `path()` such as `/@graph/0/mainEntity/1/acceptedAnswer`, the `nodeType()` and `property()` it is about (or null), and a `source()`, which is `schema.org` or the id of the profile that raised it.

The vocabulary can also be used by itself:

```php
$vocabulary = HumanMade\SchemaOrgValidator\Vocabulary::default();
$vocabulary->isSubtypeOf('NewsArticle', 'CreativeWork'); // true
$vocabulary->propertiesOf('Person');
$vocabulary->rangesOf('author');
$vocabulary->supersededBy('Code'); // ['SoftwareSourceCode']
```

## Issue codes

| Code | Severity | Meaning |
| --- | --- | --- |
| `invalid_json` | error | The input string is not valid JSON, or is not an object or a list. |
| `unknown_type` | error | The `@type` is not a schema.org type. |
| `unknown_property` | error | The key is not a schema.org property. Keywords and terms from other vocabularies are skipped. |
| `property_not_for_type` | error | The property exists, but none of the node's types, or their ancestors, are in its domains. |
| `unexpected_value_type` | warning | An object, or a reference to a node in the graph, has a type that is not in the property's ranges or their subtypes. |
| `text_for_object` | notice | A plain string was given where schema.org expects an object. This is allowed, but an object is better. |
| `invalid_value` | warning | The value does not match the property's data type (Date, DateTime, Time, Number, Integer, Float, Boolean, URL) or is not a member of its enumeration. |
| `superseded` | warning | The type or property is superseded. The message names the replacement. |
| `pending` | notice | The type or property is still in schema.org's pending area and may change. |
| `unresolved_reference` | notice | An `{"@id": ...}` reference points to a node that is not defined in the graph. |
| `missing_required` | error | A profile requires a property that is missing. |
| `missing_recommended` | warning | A profile recommends a property that is missing. |
| `deprecated_feature` | notice | A deprecated profile matches this node. |

Strings are checked in this order: enumeration members (`InStock`, `schema:InStock` or the full IRI), then the data types in the property's ranges. A range that includes `Text` accepts any value. A range with only `URL` needs an absolute URL. Dates must be ISO 8601. Data types without a format check, such as `Quantity`, accept anything. Properties with no declared domain skip the type check.

Some schema.org idioms are reported as they are written. For example, a `Role` wrapping a `Person` in `author` gives `unexpected_value_type`.

## Profiles

A profile is a set of rules for nodes of certain types. Implement the `Profile` interface, or load a `RuleProfile` from JSON:

```php
$profile = HumanMade\SchemaOrgValidator\RuleProfile::fromFile('my-profile.json');
```

```json
{
  "id": "google/article",
  "title": "Google Article rich result",
  "source": "https://developers.google.com/search/docs/appearance/structured-data/article",
  "checked": "2025-01-31",
  "status": "active",
  "statusNote": "",
  "types": ["Article"],
  "required": ["headline", { "anyOf": ["image", "thumbnailUrl"] }],
  "recommended": ["datePublished", { "path": "offers.price", "ifPresent": "offers" }]
}
```

`types` lists the types the profile applies to. It applies to every node whose type is one of them or a subtype. `status` is `active`, `limited` or `deprecated`. A `deprecated` profile adds one `deprecated_feature` notice per matching node and checks nothing else. `statusNote` is added to that notice. `source` and `checked` record where the rules came from and when they were last compared with it.

A rule is one of these:

- A property path such as `"headline"`, or a nested path such as `"offers.price"`.
- `{ "anyOf": [path, ...] }`, which passes when any path is present.
- `{ "path": "offers.price", "ifPresent": "offers" }`, which is only checked when the `ifPresent` path has a value.

Paths follow arrays, where every item must have the property, and `{"@id"}` references to nodes in the graph. A missing property is reported on the object that lacks it. Empty strings and empty arrays count as missing. Missing required properties give `missing_required` errors, and missing recommended properties give `missing_recommended` warnings. Both have the profile id as their `source`.

`Profiles::google()` loads every file in `profiles/google/`.

## Regenerating the vocabulary

`data/vocabulary.php` is generated from the schema.org JSON-LD dump and committed.

```bash
composer generate                  # latest release
composer generate -- 30.1          # a specific release
php bin/generate-vocabulary --file=schemaorg-current-https.jsonld 30.1
```

The script prints the version it generated. With `--file` it reads a local copy and uses the version argument as the label. `--output=<path>` writes somewhere other than `data/vocabulary.php`. Downloads are cached in the system temporary directory.

A weekly workflow regenerates the data and opens a pull request when it changes.

## Development

```bash
composer test
composer analyse
composer lint
```

## Licence

The code is licensed under GPL-2.0-or-later. See `LICENSE`.

The vocabulary data in `data/` is derived from [schema.org](https://schema.org) and is licensed under schema.org's data licence, not the GPL. See `data/LICENSE` and `NOTICE` for the attribution and terms.
