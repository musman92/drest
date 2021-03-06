
Change log
-----------

A list of changes made between versions

# 1.0.0

- No longer allowed to inject the EntityManager directly. A Drest\EntityManagerRegistry must be used. A convenience method is allowed to quickly set up where only a single entity manager is needed.
- Service actions can now hook onto the entity manager registry where ->getEntityManager() simple returns the default.
- Service actions are no longer constructed by Drest and must be injected. They must implement AbstractAction and be registered on the service action registry object. See http://leedavis81.github.io/drest/docs/service-actions/#creating_your_own for more information
- Removed optional support for PHP 5.6
- Dropped support for PHP 5.3 (sorry, traits are really handy), which makes this a >= PHP 5.4 tool now
- Added support for HHVM
- Added support for PHP 7
- A large number of clean up changes
- Increased code coverage
- Pushed scrutinizer quality score above 8.0: https://scrutinizer-ci.com/g/leedavis81/drest/
- Removed injectRequest config option, and always inject request object into handles. 
- Add support for YAML, PHP and JSON configuration drivers