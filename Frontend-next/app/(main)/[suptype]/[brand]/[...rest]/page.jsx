import { reportedNotFound } from '@/src/lib/notFoundReport'

// ADDRESSES NO PAGE ANSWERS (THE 404 MONITOR, 2026-11-14). A TWIN. An address of three or more segments that is not one of the
// shop's static pages (/product/x/y and the like - two segments are the facet page's, which reports its own) used to fall straight to the
// root 404 page, which cannot report it (see src/lib/notFoundReport.js). It lands here: reported, then the same
// 404. In generateMetadata as well, so the status is set before the page streams.
export async function generateMetadata() {
  await reportedNotFound()
}

export default async function UnknownAddress() {
  await reportedNotFound()
}
