# PHP 8.4 Circuit Breaker Pattern Demo

A circuit breaker implementation targeting PHP 8.4+ that prevents cascading failures when external services become unavailable. When an API or service begins failing, the circuit breaker automatically "trips open" and fails fast instead of waiting for timeouts, protecting your application from resource exhaustion and giving failing services time to recover. The pattern implements three states (CLOSED, OPEN, HALF-OPEN) with automatic recovery testing and configurable thresholds.

## Features

- ✅ **PHP 8.4 strict types** - Full type safety with enums and readonly classes
- ✅ **Flexible storage** - Memcached (distributed) storage is provided, but APCu, Redis, etc can be used.
- ✅ **HATEOAS support** - Auto-detects hypermedia links when present
- ✅ **Graceful degradation** - Continues operating if cache backend fails

## Quick Start
A test of the Circuit_Breaker_Client is found in circuit-breaker.php and can be run by executing ```php circuit-breaker.php```
