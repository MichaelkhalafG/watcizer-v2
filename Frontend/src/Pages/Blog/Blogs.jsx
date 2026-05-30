import { useEffect, useState } from 'react'
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
import http from '../../Context/api'

export default function Blogs() {
  const [blogs, setBlogs] = useState([])

  useEffect(() => {
    http
      .get('/all_blog')
      .then((response) => setBlogs(response.data))
      .catch
      // (error) => console.error("Error fetching blogs:", error)
      ()
  }, [])

  return (
    <Container sx={{ py: 6 }}>
      <Grid container spacing={4}>
        {blogs.map((blog) => {
          const blogTitle =
            blog.translations.find((t) => t.locale === 'en')?.title || 'No Title Available'
          const blogContent =
            blog.translations.find((t) => t.locale === 'en')?.text || 'No Content Available'

          return (
            <Grid item key={blog.id} xs={12} sm={6} md={4}>
              <Card sx={{ maxWidth: 345, boxShadow: 3 }}>
                <CardActionArea onClick={() => (window.location.href = `/blog/${blogTitle}`)}>
                  <CardMedia
                    component="img"
                    height="200"
                    image={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Blog/${blog.image}`}
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
                  onClick={() => (window.location.href = `/blog/${blogTitle}`)}
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
