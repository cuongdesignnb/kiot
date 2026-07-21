# PC KIOT release manifest

The SHA values below freeze the reviewed application pair. The sign-off and CI
documents may be committed after these application SHAs; those evidence-only
commits do not change the V1 runtime contract.

```yaml
kiot:
  repository: cuongdesignnb/kiot
  base_branch: production-customer-group
  base_sha: e807c4f69362d544e047939b5cc82c51ab11c3d1
  head_branch: integration/pc-products-orders-v1
  head_sha: 691a7c6932978a10d7cce8937257574ad60dfc42
  pr: 32

pc:
  repository: cuongdesignnb/pc
  base_branch: main
  base_sha: 21d02a9b82e8d802e2ad3386381bb6d02ccb1671
  head_branch: integration/kiot-products-orders-v1
  head_sha: bcb7a82c22dcbccacddc9c00d9e966f3418aba47
  pr: 1

contract:
  provider_sha: 691a7c6932978a10d7cce8937257574ad60dfc42
  endpoint_version: v1
  base_path: /api/integrations/v1/pc

release:
  merge_order:
    - kiot
    - pc
  feature_flags_default_off: true
  production_enable_authorized: false
```

`691a7c6` is the merge of the current provider base `e807c4f` into the verified
provider branch. The only new base behavior is POS checkout idempotency-key
rotation. The provider API files are unchanged from verified provider SHA
`b5a02d47194cff5bc96ccc93b1794b62f42508a7`; the affected POS tests and scoped
regression were rerun on `691a7c6`.

The immutable dependency is therefore PC consumer `bcb7a82` against KIOT
provider `691a7c6`, with the five V1 endpoints and default-off flags. Any runtime
change after these SHAs invalidates this manifest and requires review and
proportionate retesting.
