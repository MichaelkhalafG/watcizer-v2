import { useContext, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Button,
  CircularProgress,
  Typography,
  Chip,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
} from '@mui/material'
import http from '../../Context/api'
import emptyWishList from '../../assets/images/emptywishlist.svg'
import { MyContext } from '../../Context/Context'

function OrderList() {
  const { language, user_id, products, offers } = useContext(MyContext)
  const [orders, setOrders] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const getStatusColor = (status) => {
    switch (status.toLowerCase()) {
      case 'pending':
        return 'warning'
      case 'processing':
        return 'info'
      case 'shipped':
        return 'primary'
      case 'delivered':
        return 'success'
      case 'cancelled':
        return 'error'
      default:
        return 'default'
    }
  }

  useEffect(() => {
    http
      .get('me/orders')
      .then((response) => {
        setOrders(response.data)
        setLoading(false)
      })
      .catch(() => {
        setError('Failed to load orders.')
        setLoading(false)
      })
  }, [user_id])

  if (loading) return <LoadingSpinner />
  if (error) return <ErrorMessage message={error} />

  // Helper to get product/offer name
  const getProductOrOfferName = (item) => {
    if (item.product_id) {
      const prod = products?.find((p) => p.id === item.product_id)
      return prod
        ? language === 'ar'
          ? prod.product_title_ar || prod.name
          : prod.product_title || prod.name
        : item.product_id
    } else if (item.offer_id) {
      const offer = offers?.find((o) => o.id === item.offer_id)
      return offer ? (language === 'ar' ? offer.offer_name_ar : offer.offer_name_en) : item.offer_id
    }
    return '-'
  }

  return (
    <div className="container mb-md-0 mb-5 mt-4">
      <Typography variant="h5" className="fw-bold mb-3">
        {language === 'ar' ? 'قائمة طلباتك' : 'Your Orders'}
      </Typography>
      {orders.length > 0 ? (
        <TableContainer component={Paper} elevation={3} className="p-3 mb-md-0 mb-5">
          <Table>
            <TableHead>
              <TableRow>
                {[
                  'Order Num',
                  'Payment Method',
                  'Products',
                  'Total Amount',
                  'Order Status',
                  'Date',
                ].map((header, idx) => (
                  <TableCell key={idx} className="fw-bold">
                    {language === 'ar' ? arabicHeaders[idx] : header}
                  </TableCell>
                ))}
              </TableRow>
            </TableHead>
            <TableBody>
              {orders.map((order, index) => (
                <TableRow key={index}>
                  <TableCell>{order.order_number}</TableCell>
                  <TableCell>{order.payment_method}</TableCell>
                  <TableCell>
                    {order.order_item.map((product) => (
                      <div key={product.id}>
                        <span className="fw-bold">{getProductOrOfferName(product)}</span>:{' '}
                        {language === 'ar' ? ' ج.م ' : 'EG '}
                        {parseFloat(product.total_price).toFixed(2)}
                      </div>
                    ))}
                  </TableCell>
                  <TableCell>
                    {language === 'ar' ? ' ج.م ' : 'EG '}
                    {parseFloat(order.total_price_for_order).toFixed(2)}
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={order.status}
                      color={getStatusColor(order.status)}
                      variant="outlined"
                    />
                  </TableCell>
                  <TableCell>{new Date(order.created_at).toLocaleDateString()}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      ) : (
        <EmptyOrdersMessage language={language} />
      )}
    </div>
  )
}

function LoadingSpinner() {
  return (
    <div className="d-flex justify-content-center my-5">
      <CircularProgress />
    </div>
  )
}

function ErrorMessage({ message }) {
  return <Typography className="text-center text-danger fw-bold my-5">{message}</Typography>
}

function EmptyOrdersMessage({ language }) {
  return (
    <div className="d-flex flex-column align-items-center">
      <img src={emptyWishList} loading="lazy" alt="empty cart" className="col-2" />
      <Typography variant="h6" className="fw-bold mt-2">
        {language === 'ar' ? 'لا توجد طلبات' : 'No orders yet'}
      </Typography>
      <Typography variant="body1" className="text-secondary">
        {language === 'ar' ? 'ابدأ التسوق لإضافة الطلبات' : 'Start shopping to place orders'}
      </Typography>
      <Link to="/" className="mt-3">
        <Button variant="contained" className="rounded-pill bg-most-used text-light px-4 py-2">
          {language === 'ar' ? 'تسوق الآن' : 'Shop Now'}
        </Button>
      </Link>
    </div>
  )
}

const arabicHeaders = [
  'رقم الطلب',
  'طريقة الدفع',
  'المنتجات',
  'إجمالي المبلغ',
  'حالة الطلب',
  'التاريخ',
]

export default OrderList
