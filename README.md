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

$validator = new Validator(null, ...Profiles::google('google/article'));
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

When several definitions share an `@id`, they are treated as one node, as in JSON-LD node merging. Their types are combined and the values of each property are joined. Profile rules and reference paths see the merged node. Schema.org checks still report each problem at the path of the object that has it.

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

A `Role`, or a subtype such as `OrganizationRole`, is accepted as the value of any property. The real value sits under the same property name inside the Role, and it is checked against the outer property's ranges. Any schema.org property is allowed on a Role.

## Profiles

A profile is a set of rules for nodes of certain types. Implement the `Profile` interface, or load a `RuleProfile` from JSON:

```php
$profile = HumanMade\SchemaOrgValidator\RuleProfile::fromFile('my-profile.json');
```

```json
{
  "id": "google/article",
  "title": "Google Article",
  "source": "https://developers.google.com/search/docs/appearance/structured-data/article",
  "checked": "2026-09-08",
  "status": "active",
  "statusNote": "",
  "types": ["Article"],
  "required": ["headline", { "anyOf": ["image", "thumbnailUrl"] }],
  "recommended": ["datePublished", { "path": "offers.price", "ifPresent": "offers" }],
  "notes": ["Not checked: image must be at least 50K pixels."]
}
```

`types` lists the types the profile applies to. It applies to every node whose type is one of them or a subtype. `status` is `active`, `limited` or `deprecated`. A `deprecated` profile adds one `deprecated_feature` notice per matching node and checks nothing else. `statusNote` is added to that notice. `source` and `checked` record where the rules came from and when they were last compared with it. `notes` holds conditions from the source that the rules cannot express. They are for readers and are not checked.

A rule is one of these:

- A property path such as `"headline"`, or a nested path such as `"offers.price"`.
- `{ "anyOf": [path, ...] }`, which passes when any path is present.
- `{ "path": "offers.price", "ifPresent": "offers" }`, which is only checked when the `ifPresent` path has a value.

A rule object can also have `"types": ["Review"]`, which limits it to nodes of those types or their subtypes. Use this when one profile covers several root types with different rules.

Paths follow arrays, where every item must have the property, and `{"@id"}` references to nodes in the graph. A missing property is reported on the object that lacks it. Empty strings and empty arrays count as missing. Missing required properties give `missing_required` errors, and missing recommended properties give `missing_recommended` warnings. Both have the profile id as their `source`.

### Bundled Google profiles

`Profiles::google()` loads every file in `profiles/google/`. Pass ids to load only some of them, for example `Profiles::google('google/article', 'google/organization')`.

Several profiles match many nodes in an ordinary graph. Organization, Image metadata and the two Product profiles (Product snippet and Merchant listing) are the main ones, so load only the features a page is meant to qualify for.

| Profile | Status | Required rules | Recommended rules |
| --- | --- | --- | --- |
| `google/article` | active | 0 | 7 |
| `google/breadcrumb` | active | 2 | 2 |
| `google/course-info` | deprecated | 0 | 0 |
| `google/course-list` | active | 2 | 1 |
| `google/dataset` | limited | 3 | 20 |
| `google/discussion-forum` | active | 7 | 25 |
| `google/education-qa` | active | 5 | 5 |
| `google/event` | active | 4 | 18 |
| `google/faq` | deprecated | 0 | 0 |
| `google/how-to` | deprecated | 0 | 0 |
| `google/image-metadata` | active | 2 | 6 |
| `google/job-posting` | active | 6 | 12 |
| `google/local-business` | active | 2 | 17 |
| `google/merchant-listing` | active | 27 | 52 |
| `google/organization` | active | 0 | 30 |
| `google/product-snippet` | active | 5 | 10 |
| `google/profile-page` | active | 2 | 9 |
| `google/qapage` | active | 7 | 33 |
| `google/recipe` | active | 3 | 15 |
| `google/review-snippet` | active | 5 | 5 |
| `google/software-app` | active | 3 | 3 |
| `google/vacation-rental` | limited | 8 | 24 |
| `google/video` | active | 9 | 16 |

The profiles are facts transcribed from the linked Google documentation, with the date each page was checked. They are not Google's text. Rules that would flag valid markup, or that depend on a condition the format cannot express, are left out or loosened, and the condition is kept in `notes`. Google's pages are the authority, so check them when something looks wrong.

### Adding your own profile

Write a JSON file in the format above and load it, for example from a plugin:

```php
use HumanMade\SchemaOrgValidator\RuleProfile;
use HumanMade\SchemaOrgValidator\Validator;

$profile = RuleProfile::fromFile(__DIR__ . '/profiles/my-shop.json');
$validator = new Validator(null, $profile, ...Profiles::google('google/product-snippet'));
```

Give the profile a unique `id`, such as `my-plugin/shop`, so its issues can be told apart by `source()`. For rules that JSON cannot describe, implement `Profile::check( Node $node, Graph $graph ): array` and return a list of `Issue` objects.

## Regenerating the vocabulary

`data/vocabulary.php` is generated from the schema.org JSON-LD dump and committed.

```bash
composer generate                  # latest release
composer generate -- 30.1          # a specific release
php bin/generate-vocabulary --file=schemaorg-current-https.jsonld 30.1
```

The script prints the version it generated. With `--file` it reads a local copy and uses the version argument as the label. `--output=<path>` writes somewhere other than `data/vocabulary.php`. Downloads are cached in the system temporary directory.

A weekly workflow regenerates the data, runs the tests and opens a pull request when the data changes. The pull request is opened with `GITHUB_TOKEN`, and GitHub does not start other workflows for events made with that token. So the CI workflow does not run on that pull request, and only the tests inside the scheduled workflow run. To get full CI on it, give the workflow a personal access token (or a GitHub App token) through the `token` input of `peter-evans/create-pull-request`.

## Development

```bash
composer test
composer analyse
composer lint
```

## Licence

The code is licensed under GPL-2.0-or-later. See `LICENSE`.

The vocabulary data in `data/` is derived from [schema.org](https://schema.org). It is licensed under the Creative Commons Attribution-ShareAlike License (version 3.0), as stated in the [schema.org terms](https://schema.org/docs/terms.html), and not under the GPL. See `data/LICENSE` and `NOTICE` for the attribution.

The profiles in `profiles/` are our own record of facts from Google's public documentation, with links to the pages. They do not copy Google's text.
