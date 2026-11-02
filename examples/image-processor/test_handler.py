import io, unittest
from unittest.mock import Mock, patch
from PIL import Image
import handler
class Images(unittest.TestCase):
    def image(self):
        data=io.BytesIO();Image.new('RGB',(1024,800),'blue').save(data,'PNG');return data.getvalue()
    def test_dimensions(self):
        data,size=handler.resize(self.image());self.assertEqual(size,(512,400));self.assertEqual(Image.open(io.BytesIO(data)).format,'JPEG')
    def test_invalid_input(self):
        with self.assertRaises(Exception):handler.resize(b'not an image')
        with self.assertRaises(ValueError):handler.resize(b'0'*(handler.MAX_BYTES+1))
    def record(self,version='v1'):
        return {'s3':{'bucket':{'name':'fixture'},'object':{'key':'uploads%2Fphoto','versionId':version}}}
    def test_duplicate_and_reordered_versions(self):
        client=Mock();data=self.image();client.head_object.return_value={'ContentLength':len(data)}
        client.get_object.side_effect=lambda **kw:{'Body':io.BytesIO(data)}
        a=handler.process_record(self.record(),client);b=handler.process_record(self.record(),client);c=handler.process_record(self.record('v2'),client)
        self.assertEqual(a,b);self.assertNotEqual(a,c)
        self.assertEqual(client.get_object.call_args.kwargs['VersionId'],'v2')
    def test_output_prefix_ignored(self):
        r=self.record();r['s3']['object']['key']='processed/x.jpg';self.assertIsNone(handler.process_record(r,Mock()))
    def test_partial_failure(self):
        with patch('handler.boto3.client'),patch('handler.process_record',side_effect=[None,ValueError('bad')]):
            result=handler.sqs_handler({'Records':[{'messageId':'ok','body':'{"Records":[{}]}'},{'messageId':'bad','body':'{"Records":[{}]}'}]},None)
        self.assertEqual(result,{'batchItemFailures':[{'itemIdentifier':'bad'}]})
if __name__=='__main__':unittest.main()
