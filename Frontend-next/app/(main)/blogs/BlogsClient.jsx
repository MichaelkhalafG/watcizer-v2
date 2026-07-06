'use client'
import Link from 'next/link'
import {
  Card,
  CardMedia,
  CardContent,
  CardActionArea,
  Typography,
  Button,
  Grid,
  Container,
} from '@mui/material'
import { getImageUrl } from '@/src/utils/imageUrl'

// Blogs list — ported from Frontend Blogs.jsx. The blog array is fetched on the
// server (ISR) and passed in as a prop, so the list is in the initial HTML.
// window.location navigation → next/link (client routing to /blog/[name]); the
// URL key is the english blog title, url-encoded.
export default function BlogsClient({ blogs = [] }) {
  return (
    <Container sx={{ py: 6 }}>
      <Grid container spacing={4}>
        {blogs.map((blog) => {
          const blogTitle =
            blog.translations.find((t) => t.locale === 'en')?.title || 'No Title Available'
          const blogContent =
            blog.translations.find((t) => t.locale === 'en')?.text || 'No Content Available'
          const href = `/blog/${encodeURIComponent(blogTitle)}`

          return (
            <Grid item key={blog.id} xs={12} sm={6} md={4}>
              <Card sx={{ maxWidth: 345, boxShadow: 3 }}>
                <CardActionArea component={Link} href={href}>
                  <CardMedia
                    component="img"
                    height="200"
                    image={getImageUrl(blog.image, 'Blog')}
                    alt={blogTitle}
                  />
                  <CardContent>
                    <Typography gutterBottom variant="h6" component="div">
                      {blogTitle}
                    </Typography>
                    <Typography variant="body2" color="text.secondary" noWrap>
                      {blogContent}
                    </Typography>
                  </CardContent>
                </CardActionArea>
                <Button
                  fullWidth
                  variant="contained"
                  color="primary"
                  component={Link}
                  href={href}
                >
                  Read More
                </Button>
              </Card>
            </Grid>
          )
        })}
      </Grid>
    </Container>
  )
}
