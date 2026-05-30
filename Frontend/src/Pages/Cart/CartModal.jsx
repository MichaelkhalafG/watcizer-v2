import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
} from '@mui/material'
import { useNavigate } from 'react-router-dom'
import { useContext } from 'react'
import { MyContext } from '../../Context/Context'

function CartModal({ open, onClose, cart }) {
  const { products, offers } = useContext(MyContext)
  const navigate = useNavigate()

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>
        🛒 {cart.cart_item?.length > 0 ? 'Items Added to Cart' : 'Your Cart is Empty'}
      </DialogTitle>
      <DialogContent>
        {cart.cart_item && cart.cart_item.length > 0 ? (
          cart.cart_item.map((item, idx) => {
            let name = ''
            let image = ''
            if (item.product_id !== null && item.product_id !== undefined) {
              const product = products.find((p) => p.id === item.product_id)
              name = product?.name || item.product_title || ''
              image = product?.image || item.image || item.product_image || ''
            } else {
              const offer = offers.find((o) => String(o.id) === String(item.offer_id))
              name = offer ? offer.offer_name_en || offer.offer_name_ar : item.offer_name_en || ''
              image = offer?.image || item.image || item.offer_image || ''
            }
            return (
              <Box key={idx} display="flex" alignItems="center" mb={2}>
                <img
                  src={image}
                  alt={name}
                  style={{
                    width: 60,
                    height: 60,
                    objectFit: 'cover',
                    borderRadius: 8,
                    marginRight: 16,
                    background: '#f0f0f0',
                  }}
                  onError={(e) => {
                    e.target.src = '/logo.svg'
                  }}
                />
                <Typography variant="body1">{name}</Typography>
              </Box>
            )
          })
        ) : (
          <Typography variant="body2" color="textSecondary">
            No items in cart.
          </Typography>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} variant="outlined" color="primary">
          Continue Shopping
        </Button>
        <Button
          onClick={() => {
            onClose()
            navigate('/cart')
          }}
          variant="contained"
          color="primary"
        >
          Go to Cart
        </Button>
      </DialogActions>
    </Dialog>
  )
}

export default CartModal
