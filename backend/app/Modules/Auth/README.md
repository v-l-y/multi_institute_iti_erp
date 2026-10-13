# Auth module

`Http/` contains the API controller and authentication middleware. `Application/` owns login and session workflows. `Domain/` enforces permission and institute access. `Infrastructure/` contains password hashing and the worker-local login throttle. No compatibility aliases or parallel auth master are retained.
