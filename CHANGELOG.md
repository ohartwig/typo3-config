## [5.7.6](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.5...v5.7.6) (2026-08-22)

### :bug: Fixes

* **mail:** a DSN that is set and never read is worse than no DSN ([61b96f2](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/61b96f2e5cb339e85a35f5e0029bdb4f55c3f718))

## [5.7.5](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.4...v5.7.5) (2026-08-15)

### :repeat: Chores

* **ci:** drop the local .releaserc.yml, which was overriding the preset ([48a2074](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/48a20741eb634d8f0887c3bdef430eb2cb5d2f97))

## [5.7.4](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.3...v5.7.4) (2026-08-15)


### Bug Fixes

* **deps:** update dependency php to ^8.5.9 ([a7513d2](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/a7513d255204c6fe2536f781f56000b6a17609ff))

## [5.7.3](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.2...v5.7.3) (2026-08-15)


### Bug Fixes

* **tests:** order tests by dependency and chance, never by defects ([626f17a](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/626f17adf5bbeb089f7c74d71b8f70a0501657ef))

## [5.7.2](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.1...v5.7.2) (2026-08-14)


### Bug Fixes

* allow the infection extension installer plugin ([b695d5f](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/b695d5f409a92cee21a3297e66b581698d0ede7d))
* **ci:** point at the current hosts ([2e2a7ef](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/2e2a7ef3ce067ea802845534416824b81e70082b))

## [5.7.1](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.7.0...v5.7.1) (2026-08-12)


### Bug Fixes

* **security:** add coding-agent to .gitsigners ([e8d0e9f](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/e8d0e9fa7510babd2e5c9e50b282f7874f18f332))

# [5.7.0](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.6.2...v5.7.0) (2026-08-07)


### Features

* **commit-signing:** add .gitsigners + lefthook hint (G-SDLC-002 step 3) ([8c4e2ad](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/8c4e2adfcffa48edc1da6c4fee4b6c51cbaf2101))

## [5.6.2](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.6.1...v5.6.2) (2026-07-29)


### Bug Fixes

* **docs:** point at the handbook repository, not an unreachable domain ([1209141](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/1209141c12db5f686ac4737253772c6ddf34e5b6))

## [5.6.1](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.6.0...v5.6.1) (2026-07-28)


### Bug Fixes

* **preset:** clear the backend HTTPS lock in the development preset ([0150d6f](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/0150d6f9971a29ddb3a505a923ffb5f4ad47a4ff))

# [5.6.0](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.5.4...v5.6.0) (2026-07-28)


### Features

* attach the edge request id to every log record ([e79d9c7](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/e79d9c7d2434445d05ae64095e60c2ce0e50381c))

## [5.5.4](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.5.3...v5.5.4) (2026-07-24)


### Bug Fixes

* **ci:** drop github-mirror (no public mirroring for now) ([8624763](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/8624763a4f28824483667bd319006475cf01add6))

## [5.5.3](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.5.2...v5.5.3) (2026-07-24)


### Bug Fixes

* **ci:** adopt github-mirror 1.2.10 (skip mirror when no token) ([8da82f8](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/8da82f875d9e44dcf72c262e1cc59678778cf04a))
* **ci:** github-mirror 1.2.11 (contains skip-if-no-token) ([3196778](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/3196778d314337a589e9552407142596b8777fa6))

## [5.5.2](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.5.1...v5.5.2) (2026-06-10)


### Bug Fixes

* **security:** patch H3 CLI-preset + M7 revproxy default + M8 denylist depth ([033f518](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/033f51844a5e8f63b741927ba8c7e0ac26aa74a8))

## [5.5.1](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.5.0...v5.5.1) (2026-06-08)


### Bug Fixes

* **tests:** migrate [@test](https://git.ole-hartwig.eu/test) annotation to PHPUnit 13 #[Test] attribute ([40361b7](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/40361b79654a85dc25028d697aa36c9bd6e7fbe4))

# [5.5.0](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.4.5...v5.5.0) (2026-06-07)


### Features

* **release:** add develop branch as rc-prerelease channel ([7891f75](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/7891f75665aeb08333ad05c893e4c3e7ae475601))

## [5.4.5](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.4.4...v5.4.5) (2026-06-07)


### Bug Fixes

* **release:** drop [skip ci] from semantic-release commit ([2dae982](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/2dae982f1b87cfaa168ccc1d9aa1876c74e5628e))

## [5.4.4](https://git.ole-hartwig.eu/development/moselwal/typo3-config/compare/v5.4.3...v5.4.4) (2026-06-07)


### Bug Fixes

* **ci:** allow_failure on 9 known-broken component jobs ([c139808](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/c139808796451b4e5d9bce36b3eeca7a24c4d97c))
* composer normalize + require-checker whitelist for transitive symbols ([a0482ab](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/a0482abfa6a585af21d2f338f26a8982a5a73c9e))
* **composer:** add homepage field ([0d05253](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/0d052535ec5e9b9198cd649b7280d4764eb46202))
* **md:** replace bare fenced codeblocks with ```text ([da1c1d5](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/da1c1d57b282e0aab9f1948e4c6c4fe175c7c1ba))
* **md:** scope markdownlint to public surfaces ([674705c](https://git.ole-hartwig.eu/development/moselwal/typo3-config/commit/674705cfde49c4c0573166549bc4a73a8b9c33ba))
