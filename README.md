# Forge Kernel

The minimal, no-magic core of Forge. It handles bootstrapping, dependency
injection, module loading, configuration, and CLI infrastructure. Everything
else — database, routing, authentication, storage, templating — is a capability
you plug in when you need it.

You do not build Forge applications. You build your application on top of the
Forge kernel. Kernel + capabilities (modules) + your own code = your
application. You assemble, you own, you decide.

## What's in Here

- **DI Container** — automatic dependency resolution with zero configuration
- **Module System** — loader, lifecycle hooks, module registry
- **CLI Kernel** — command routing, scaffolding, and tooling
- **Configuration Manager** — config files and environment variables
- **Bootstrap** — initializes the kernel for CLI or web contexts
- **Autoloader** — PSR-4 compliant
- **Cache** — with proxy generation for transparent caching
- **Session Management** — driver-based session handling
- **Validation** — simple validation definitions and rules
- **Contracts** — interfaces for database, view, cache, and other capabilities
  (implementations come from modules)
- **Helpers and Traits** — common patterns shared across modules
- **Debug, Observability, DTO, Events, Security** — supporting infrastructure

## Why It Exists

Forge exists because I wanted a fast, simple, no-magic kernel that puts me in
control. No assumptions about what my application looks like. No baked-in
opinions about databases, routers, or templating. The kernel stays lean. You
stay in control.

- You are not a user. You are a builder.
- Fork it, change it, make it yours.
- If the direction doesn't fit yours, forge your own path.
- Updates are available if you want them. Ignore what doesn't help.

This is not a product. This is a toolbox.

## Resources

- [Module Registry](https://github.com/forge-kernel/modules) — official
  capability modules
- [Blueprints](https://github.com/forge-kernel/blueprints) — project templates
  to get started
- [FORGING-YOUR-OWN.md](./docs/FORGING-YOUR-OWN.md) — how to build your own
  kernel distribution

## License

MIT — take it, use it, change it.
