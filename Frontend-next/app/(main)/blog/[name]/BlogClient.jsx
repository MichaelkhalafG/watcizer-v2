'use client'
import { useMemo } from 'react'
import { Container, Typography, Box, Grid } from '@mui/material'
import { useUIStore } from '@/src/Store/uiStore'
import { getImageUrl } from '@/src/utils/imageUrl'

// Blog detail — ported from Frontend Blog.jsx. The blog is resolved on the server
// (by english title) and passed in as a prop, so the content is in the initial
// HTML; the client only localizes the title/text to the active language.
export default function BlogClient({ blog: raw }) {
  const { language } = useUIStore()

  const blog = useMemo(() => {
    if (!raw) return { title: 'Blog Not Found', content: 'No content available.', images: [], image: '' }
    const translation =
      raw.translations?.find((t) => t.locale === language) ||
      raw.translations?.find((t) => t.locale === 'en')
    return {
      title: translation?.title,
      content: translation?.text,
      images: raw.images || [],
      image: raw.image || '',
    }
  }, [raw, language])

  return (
    <Container maxWidth="lg">
      {blog.image && (
        <Box display="flex" justifyContent="center" my={4}>
          <img
            src={getImageUrl(blog.image, 'Blog')}
            alt="Blog"
            style={{
              width: '100%',
              maxHeight: '400px',
              objectFit: 'cover',
              borderRadius: '12px',
              boxShadow: '0px 4px 10px rgba(0, 0, 0, 0.2)',
            }}
          />
        </Box>
      )}
      <Typography variant="h4" fontWeight="bold" textAlign="center" gutterBottom>
        {blog.title}
      </Typography>
      <Typography
        variant="body1"
        color="text.secondary"
        sx={{ textAlign: 'justify', lineHeight: 1.8, letterSpacing: '0.5px', mt: 2 }}
      >
        {blog.content}
      </Typography>
      <Grid container spacing={2} mt={4}>
        {blog.images.map((image, index) => (
          <Grid item xs={12} sm={6} key={index}>
            <img
              src={getImageUrl(image.image, 'Blog_image')}
              alt="Blog"
              style={{
                width: '100%',
                height: 'auto',
                borderRadius: '10px',
                boxShadow: '0px 4px 8px rgba(0, 0, 0, 0.1)',
              }}
            />
          </Grid>
        ))}
      </Grid>
    </Container>
  )
}
