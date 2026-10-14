# F02 — Optional product images
User approved optional product images on 2026-10-10. The image software is implemented in the coordinated POS/product-tools batch. Actual storage requires privately configured R2; S6 alone did not implement it.

## Approved behavior
- Product form accepts an optional image; list and later POS show a thumbnail to help staff recognise items.
- Products without images have a simple placeholder.
- Compress images and load thumbnails only when needed for cheap Android phones and slow connections.
- Preserve the supplied emerald/slate layout and existing product components.

## Required implementation safeguards
Use the approved Cloudflare R2/Laravel S3 stack, business-scoped file paths and server-authoritative upload validation/permissions. Do not expose another business's private images or accept client business IDs. Do not add an image-processing dependency without approval. Specify the upload/replace/remove API, safe MIME/size handling, access contract and transactional cleanup before coding. Verify upload failure, tenant isolation, product archive/history and responsive layouts. No simulated upload success or external provider configuration is supplied by this scope approval.


## Implemented interface
ProductResource returns nullable image metadata (revision, MIME, bytes, dimensions), never private paths. Authenticated image bytes are decoded only into temporary component Blob URLs, revoked on change/unmount; RTK stores serializable response data in memory. Catalogue/POS images load lazily; the edit form compresses JPEG/PNG/WebP through native canvas to 1280 pixels, JPEG quality 0.82, at most 2 MB. New products are saved before editing their optional image.
POST/DELETE products/{product}/image require manage_products and an expected revision; GET products/{product}/image/{revision} requires approved-device product access. Uploaded MIME/dimensions/bytes are validated by Laravel; SVG is rejected. Random tenant paths, optimistic revisions, transactional metadata and durable postcommit cleanup protect replacement/removal. Archive retains private metadata and access is denied to archived resources. products:cleanup-images runs hourly and cannot delete currently referenced paths, including archived products.
R2 uses the approved Laravel S3 adapter. Its unsupported object ACL header is stripped; configure a private bucket with public R2.dev/custom-domain access disabled. Missing private server configuration returns IMAGE_STORAGE_UNCONFIGURED, with no simulated upload. See filesystems.php and blank R2_* example variables; secrets stay server-side.
