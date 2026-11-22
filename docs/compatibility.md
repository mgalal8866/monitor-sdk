# Compatibility

| SDK | PHP | Laravel | Central API |
|---|---|---|---|
| 1.x | 8.2+ | 11, 12, 13 | v1 |

The SDK follows Semantic Versioning, independently of the central API version. The central server
advertises its minimum SDK version and features on `GET /api/v1/monitor/config`.

Verified in this repository's test suite against Laravel 13.43 (Testbench 11) and end-to-end with a
fresh Laravel 13 application. Laravel 11/12 are declared in the constraints and must be verified in CI
with `composer update --prefer-lowest` / matrix builds before a 1.0 release.
